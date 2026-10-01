<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Controlled WordPress media M2 import (production-safe).
 *
 * Destination authority: legacy entity ID → Run-scoped legacy_import_maps → local_id.
 * Staging plan local_entity_id values are diagnostic only and NEVER write targets.
 *
 * Transfer: local approved archive only (WORDPRESS_MEDIA_SOURCE_ROOT). No HTTP fallback
 * unless wordpress.media.allow_http is explicitly true (forbidden in production).
 *
 * Dry-run by default. Real mutation requires REAL_PERSIST + active migration run.
 * Processes ONLY the 148-file M1 transfer manifest (+ video/inline plans).
 * Competition physical media is out of scope.
 */
final class WordPressMediaImporter
{
    public const SKIPPED_LEGACY_PRODUCT_ID = 6912;

    private const MANIFEST = 'docs/media-m1/media-transfer-manifest.json';

    private const VIDEO_RESOLUTION = 'docs/media-m1/entity-video-resolution.json';

    private const INLINE_PLAN = 'docs/media-m1/inline-image-rewrite-plan.json';

    public function __construct(
        private readonly WordPressRealPersistGate $persistGate,
        private readonly MigrationRunService $runs,
        private readonly WordPressMediaTransferPlanner $planner,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressMediaOwnershipResolver $ownership,
        private readonly WordPressMediaLocalSource $localSource,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(int $migrationRunId, bool $dryRun = true): array
    {
        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'media')) {
            return $block;
        }

        if (! $dryRun) {
            $this->runs->bindRunning($migrationRunId);
            // WordPress legacy assets include large GIFs; Spatie default 10MB is too low for M2.
            config(['media-library.max_file_size' => 1024 * 1024 * 120]);
        } else {
            $run = \App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun::query()->find($migrationRunId);
            if ($run === null || $run->status !== MigrationRunService::STATUS_RUNNING) {
                throw new RuntimeException("Migration run {$migrationRunId} must exist and be running.");
            }
        }

        $manifest = $this->loadJson(base_path(self::MANIFEST));
        $videos = $this->loadJson(base_path(self::VIDEO_RESOLUTION));
        $inline = $this->loadJson(base_path(self::INLINE_PLAN));

        $items = $manifest['items'] ?? [];
        if (count($items) !== 148) {
            throw new RuntimeException('Transfer manifest must contain exactly 148 items, got '.count($items));
        }

        foreach ($items as $item) {
            if (($item['availability'] ?? null) !== 'AVAILABLE') {
                throw new RuntimeException('Manifest item not AVAILABLE: '.($item['legacy_attachment_id'] ?? '?'));
            }
        }

        $sourceRoot = $this->localSource->sourceRoot();
        $hashManifest = $this->localSource->hashManifestPath();

        $report = [
            'status' => $dryRun ? 'dry_run' : 'executed',
            'entity_type' => 'media',
            'dry_run' => $dryRun,
            'migration_run_id' => $migrationRunId,
            'wrote_to_database' => false,
            'destination_resolution' => 'legacy_id -> run_scoped_map -> production local_id',
            'staging_local_id_authority' => false,
            'http_fallback_enabled' => $this->localSource->allowHttp(),
            'media_source_root' => $sourceRoot,
            'hash_manifest_path' => $hashManifest,
            'files_expected' => 148,
            'files_present' => 0,
            'files_hash_verified' => 0,
            'files_hash_mismatch' => 0,
            'files_missing' => 0,
            'files_downloaded' => 0,
            'files_reused' => 0,
            'files_failed' => 0,
            'files_to_create' => 0,
            'files_already_identical' => 0,
            'files_collision' => 0,
            'bytes_downloaded' => 0,
            'files_to_download' => 0,
            'external_downloads' => 0,
            'product_featured_attached' => 0,
            'product_gallery_attached' => 0,
            'product_media_to_attach' => 0,
            'product_featured_planned' => 0,
            'product_gallery_planned' => 0,
            'product_6912_skipped_usages' => 0,
            'course_images_local' => 0,
            'course_images_to_change' => 0,
            'course_images_planned' => 0,
            'lesson_video_imported' => 0,
            'lesson_videos_to_change' => 0,
            'lesson_videos_planned' => 0,
            'lesson_video_deferred' => 0,
            'lesson_no_video' => 0,
            'topic_video_imported' => 0,
            'topic_videos_to_change' => 0,
            'topic_videos_planned' => 0,
            'course_video_deferred' => 1,
            'inline_images_stored' => 0,
            'inline_replacements_planned' => 0,
            'lessons_rewritten' => 0,
            'content_fields_changed' => 0,
            'url_replacements' => 0,
            'unresolved_rewrites' => 0,
            'unresolved_targets' => 0,
            'staging_destination_mismatches' => 0,
            'native_overwrites_planned' => 0,
            'competition_media_import' => 0,
            'db_writes' => 0,
            'live_file_writes' => 0,
            'http_requests' => 0,
            'conflicts' => [],
            'failures' => [],
            'skipped' => [],
            'deferred' => [],
            'transfer_records' => [],
        ];

        if ($sourceRoot === null || $hashManifest === null || ! is_file((string) $hashManifest)) {
            $report['status'] = $dryRun ? 'dry_run_blocked' : 'blocked';
            $report['message'] = 'WORDPRESS_MEDIA_SOURCE_ROOT and approved hash manifest are required (local archive ingest; no HTTP fallback).';
            $report['files_missing'] = 148;

            return $report;
        }

        $ownership = [
            'migration_run_id' => $migrationRunId,
            'files' => [],
            'spatie_media_ids' => [],
            'course_image_changes' => [],
            'lesson_video_changes' => [],
            'topic_video_changes' => [],
            'content_rewrites' => [],
            'skipped_product_6912' => [],
            'physical_paths' => [],
            'resolved_destinations' => [],
        ];

        $workRoot = $this->workRoot($migrationRunId);
        if (! $dryRun) {
            if (! is_dir($workRoot) && ! mkdir($workRoot, 0755, true) && ! is_dir($workRoot)) {
                throw new RuntimeException("Cannot create work root {$workRoot}");
            }
        }

        // ---- Phase A: transfer all 148 ----
        $transferred = [];
        foreach ($items as $item) {
            $aid = (int) $item['legacy_attachment_id'];
            try {
                $result = $this->transferOne($item, $migrationRunId, $dryRun, $workRoot);
                if ($result['status'] === 'failed') {
                    $report['files_failed']++;
                    if (($result['code'] ?? null) === 'HASH_MISMATCH' || ($result['code'] ?? null) === 'SIZE_MISMATCH') {
                        $report['files_hash_mismatch']++;
                    }
                    if (($result['code'] ?? null) === 'MISSING_LOCAL_SOURCE' || ($result['code'] ?? null) === 'HASH_MANIFEST_ENTRY_MISSING') {
                        $report['files_missing']++;
                    }
                    $report['failures'][] = $result;
                    $transferred[$aid] = $result;
                    continue;
                }
                if (in_array($result['status'], ['reused', 'verified_local', 'would_use_local'], true)) {
                    $report['files_present']++;
                    if (! empty($result['sha256'])) {
                        $report['files_hash_verified']++;
                    }
                }
                if ($result['status'] === 'reused') {
                    $report['files_reused']++;
                    $report['files_already_identical']++;
                } elseif ($result['status'] === 'copied_local') {
                    $report['files_downloaded']++; // historical counter name: local copy
                    $report['files_to_create']++;
                    if (! $dryRun) {
                        $report['live_file_writes']++;
                    }
                    $report['bytes_downloaded'] += (int) ($result['actual_bytes'] ?? 0);
                } elseif ($result['status'] === 'would_use_local') {
                    $report['files_to_download']++; // planned local ingest
                    $report['files_to_create']++;
                } elseif ($result['status'] === 'verified_local') {
                    $report['files_reused']++;
                }
                $transferred[$aid] = $result;
                $report['transfer_records'][] = [
                    'legacy_attachment_id' => $aid,
                    'sha256' => $result['sha256'] ?? null,
                    'actual_bytes' => $result['actual_bytes'] ?? null,
                    'target_path' => $result['target_path'] ?? null,
                    'status' => $result['status'],
                ];
                if (! $dryRun && isset($result['target_path'])) {
                    $ownership['files'][] = $result;
                    $ownership['physical_paths'][] = $result['target_path'];
                }
            } catch (Throwable $e) {
                $report['files_failed']++;
                $report['failures'][] = [
                    'legacy_attachment_id' => $aid,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($report['files_failed'] > 0) {
            $report['status'] = $dryRun ? 'dry_run_blocked' : 'stopped_after_transfer_failures';
            $report['message'] = 'Transfer failures — entity mutation aborted.';
            if (! $dryRun) {
                $this->writeOwnership($migrationRunId, $ownership);
            }

            return $report;
        }

        // ---- Phase B: product media ----
        foreach ($items as $item) {
            $aid = (int) $item['legacy_attachment_id'];
            $file = $transferred[$aid] ?? null;
            foreach ($item['all_planned_usages'] ?? [] as $usage) {
                $action = $usage['future_import_action'] ?? '';
                if (! in_array($action, ['PRODUCT_IMAGE_ATTACH', 'PRODUCT_GALLERY_ATTACH'], true)) {
                    continue;
                }
                $legacyProductId = (int) ($usage['legacy_entity_id'] ?? 0);
                $stagingLocalId = (int) ($usage['local_entity_id'] ?? 0);
                if ($legacyProductId === self::SKIPPED_LEGACY_PRODUCT_ID) {
                    $report['product_6912_skipped_usages']++;
                    $report['skipped'][] = [
                        'reason' => 'PRODUCT_6912_NOT_MIGRATED',
                        'legacy_attachment_id' => $aid,
                        'legacy_product_id' => $legacyProductId,
                        'action' => $action,
                        'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                    ];
                    $ownership['skipped_product_6912'][] = [
                        'legacy_attachment_id' => $aid,
                        'action' => $action,
                    ];
                    continue;
                }

                $resolved = $this->ownership->resolve($migrationRunId, 'product', (string) $legacyProductId);
                if ($resolved['status'] !== WordPressMediaOwnershipResolver::STATUS_OK) {
                    $report['unresolved_targets']++;
                    $report['deferred'][] = [
                        'reason' => $resolved['code'] ?? $resolved['status'],
                        'entity_type' => 'product',
                        'legacy_entity_id' => $legacyProductId,
                        'legacy_attachment_id' => $aid,
                        'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                    ];
                    continue;
                }
                $localProductId = (int) $resolved['local_id'];
                if ($stagingLocalId > 0 && $stagingLocalId !== $localProductId) {
                    $report['staging_destination_mismatches']++;
                }
                $this->ownership->assertStagingIdNotAuthoritative(
                    $stagingLocalId > 0 ? $stagingLocalId : null,
                    $localProductId,
                );

                $collection = $action === 'PRODUCT_IMAGE_ATTACH' ? 'image' : 'gallery';
                $order = (int) ($usage['planned_order'] ?? 0);

                $product = Product::query()->find($localProductId);
                if ($product === null) {
                    $report['conflicts'][] = [
                        'type' => 'MISSING_PRODUCT',
                        'local_product_id' => $localProductId,
                        'legacy_product_id' => $legacyProductId,
                        'legacy_attachment_id' => $aid,
                    ];
                    continue;
                }

                if ($action === 'PRODUCT_IMAGE_ATTACH') {
                    $report['product_featured_planned']++;
                } else {
                    $report['product_gallery_planned']++;
                }

                if ($this->planner->alreadyAttached($product->getMedia($collection), $aid)) {
                    continue;
                }

                if ($dryRun) {
                    $report['product_media_to_attach']++;
                    continue;
                }

                $path = $file['target_path'] ?? null;
                if (! is_string($path) || ! is_file($path)) {
                    $report['failures'][] = ['error' => "Missing file for product attach aid={$aid}"];
                    continue;
                }

                $media = $product
                    ->addMedia($path)
                    ->preservingOriginal()
                    ->withCustomProperties($this->planner->idempotencyCustomProperties(
                        $aid,
                        (string) $item['relative_path'],
                        (string) $item['canonical_source_url'],
                        $migrationRunId,
                    ))
                    ->toMediaCollection($collection);

                if ($collection === 'gallery') {
                    $media->order_column = $order + 1;
                    $media->save();
                }

                $ownership['spatie_media_ids'][] = $media->id;
                $ownership['resolved_destinations'][] = [
                    'entity_type' => 'product',
                    'legacy_id' => $legacyProductId,
                    'local_id' => $localProductId,
                    'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                ];
                if ($action === 'PRODUCT_IMAGE_ATTACH') {
                    $report['product_featured_attached']++;
                } else {
                    $report['product_gallery_attached']++;
                }
                $report['wrote_to_database'] = true;
                $report['db_writes']++;

                $this->maps->upsertMapping('media_attachment', 'product:'.$legacyProductId.':'.$collection.':'.$aid, [
                    'local_id' => $media->id,
                    'migration_run_id' => $migrationRunId,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipCreated([
                        'kind' => 'spatie_media',
                        'product_id' => $product->id,
                        'collection' => $collection,
                        'legacy_attachment_id' => $aid,
                        'order' => $order,
                    ]),
                ]);
            }
        }

        // ---- Phase C: course images ----
        foreach ($items as $item) {
            foreach ($item['all_planned_usages'] ?? [] as $usage) {
                if (($usage['future_import_action'] ?? '') !== 'COURSE_IMAGE_STORE') {
                    continue;
                }
                $aid = (int) $item['legacy_attachment_id'];
                $legacyCourseId = (int) ($usage['legacy_entity_id'] ?? 0);
                $stagingLocalId = (int) ($usage['local_entity_id'] ?? 0);
                $resolved = $this->ownership->resolve($migrationRunId, 'course', (string) $legacyCourseId);
                if ($resolved['status'] !== WordPressMediaOwnershipResolver::STATUS_OK) {
                    $report['unresolved_targets']++;
                    $report['deferred'][] = [
                        'reason' => $resolved['code'] ?? $resolved['status'],
                        'entity_type' => 'course',
                        'legacy_entity_id' => $legacyCourseId,
                        'legacy_attachment_id' => $aid,
                        'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                    ];
                    continue;
                }
                $localCourseId = (int) $resolved['local_id'];
                if ($stagingLocalId > 0 && $stagingLocalId !== $localCourseId) {
                    $report['staging_destination_mismatches']++;
                }
                $this->ownership->assertStagingIdNotAuthoritative(
                    $stagingLocalId > 0 ? $stagingLocalId : null,
                    $localCourseId,
                );

                $course = Course::query()->find($localCourseId);
                if ($course === null) {
                    $report['conflicts'][] = [
                        'type' => 'MISSING_COURSE',
                        'local_course_id' => $localCourseId,
                        'legacy_attachment_id' => $aid,
                    ];
                    continue;
                }
                $report['course_images_planned']++;
                $plan = $this->planner->courseImagePlan($aid, (string) $item['relative_path']);
                $relative = $plan['planned_relative_path'];
                $current = (string) ($course->image_url ?? '');
                if ($current === $relative) {
                    continue;
                }
                // If already a local M2 path for same attachment, skip; if non-empty native non-matching — still overwrite WP URLs only.
                if ($current !== '' && ! str_contains($current, 'sciencestreetlab.com') && ! str_starts_with($current, 'courses/') && $current !== $relative) {
                    // Allow overwrite of leftover WordPress absolute URLs; conflict only for unrelated local paths not matching convention.
                    if (! str_starts_with($current, 'http') && $current !== $relative) {
                        $report['conflicts'][] = [
                            'type' => 'COURSE_IMAGE_CONFLICT',
                            'course_id' => $course->id,
                            'current' => $current,
                            'planned' => $relative,
                        ];
                        $report['files_collision']++;
                        $report['native_overwrites_planned']++;
                        continue;
                    }
                }

                if ($dryRun) {
                    $report['course_images_to_change']++;
                    continue;
                }

                $src = $transferred[$aid]['target_path'] ?? null;
                if (! is_string($src) || ! is_file($src)) {
                    $report['failures'][] = ['error' => "Missing file for course {$course->id} aid={$aid}"];
                    continue;
                }
                $destAbs = Storage::disk('public')->path($relative);
                $destDir = dirname($destAbs);
                if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
                    throw new RuntimeException("Cannot create {$destDir}");
                }
                if (is_file($destAbs)) {
                    $existingHash = hash_file('sha256', $destAbs);
                    $srcHash = hash_file('sha256', $src);
                    if ($existingHash !== $srcHash) {
                        $report['conflicts'][] = [
                            'type' => 'COURSE_IMAGE_HASH_COLLISION',
                            'course_id' => $course->id,
                            'path' => $destAbs,
                        ];
                        $report['files_collision']++;
                        continue;
                    }
                } else {
                    if (! copy($src, $destAbs)) {
                        throw new RuntimeException("Failed copying course image to {$destAbs}");
                    }
                    $report['live_file_writes']++;
                }
                $before = $course->image_url;
                $course->image_url = $relative;
                $course->save();
                $ownership['course_image_changes'][] = [
                    'course_id' => $course->id,
                    'legacy_course_id' => $legacyCourseId,
                    'before' => $before,
                    'after' => $relative,
                    'absolute_path' => $destAbs,
                    'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                ];
                $ownership['physical_paths'][] = $destAbs;
                $report['course_images_local']++;
                $report['wrote_to_database'] = true;
                $report['db_writes']++;

                $this->maps->upsertMapping('media_course_image', 'course:'.$legacyCourseId.':'.$aid, [
                    'local_id' => $course->id,
                    'migration_run_id' => $migrationRunId,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                        'kind' => 'course_image_url',
                        'before' => $before,
                        'after' => $relative,
                        'absolute_path' => $destAbs,
                        'legacy_attachment_id' => $aid,
                    ]),
                ]);
            }
        }

        // ---- Phase D: lesson videos ----
        foreach ($videos['entities'] ?? [] as $entity) {
            if (($entity['entity_type'] ?? '') !== 'lesson') {
                continue;
            }
            $status = $entity['resolution_status'] ?? '';
            $legacyId = (int) ($entity['legacy_entity_id'] ?? 0);
            $stagingLocalId = (int) ($entity['local_entity_id'] ?? 0);

            if ($status === 'NO_VIDEO') {
                $report['lesson_no_video']++;
                continue;
            }
            if ($status === 'MULTIPLE_DISTINCT_VIDEOS' || $legacyId === 8701) {
                $report['lesson_video_deferred']++;
                $report['deferred'][] = [
                    'reason' => 'MULTIPLE_DISTINCT_VIDEOS',
                    'entity_type' => 'lesson',
                    'legacy_entity_id' => $legacyId,
                    'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                ];
                continue;
            }
            if (! in_array($status, ['ONE_CLEAR_VIDEO', 'MULTIPLE_REFERENCES_SAME_VIDEO'], true)) {
                $report['lesson_video_deferred']++;
                continue;
            }

            $resolved = $this->ownership->resolve($migrationRunId, 'lesson', (string) $legacyId);
            if ($resolved['status'] !== WordPressMediaOwnershipResolver::STATUS_OK) {
                $report['unresolved_targets']++;
                $report['deferred'][] = [
                    'reason' => $resolved['code'] ?? $resolved['status'],
                    'entity_type' => 'lesson',
                    'legacy_entity_id' => $legacyId,
                    'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                ];
                continue;
            }
            $localId = (int) $resolved['local_id'];
            if ($stagingLocalId > 0 && $stagingLocalId !== $localId) {
                $report['staging_destination_mismatches']++;
            }

            $lesson = Lesson::query()->find($localId);
            if ($lesson === null) {
                $report['conflicts'][] = ['type' => 'MISSING_LESSON', 'local_id' => $localId, 'legacy_id' => $legacyId];
                continue;
            }

            $canonical = $entity['canonical_video_url'] ?? null;
            $provider = $entity['provider'] ?? 'youtube';
            if (! is_string($canonical) || $canonical === '') {
                $report['lesson_video_deferred']++;
                continue;
            }

            $report['lesson_videos_planned']++;

            $existingUrl = $lesson->video_url;
            $existingProvider = $lesson->video_provider;
            if ($existingUrl !== null && $existingUrl !== '' && $existingUrl !== $canonical) {
                $report['conflicts'][] = [
                    'type' => 'LESSON_VIDEO_CONFLICT',
                    'lesson_id' => $lesson->id,
                    'existing_url' => $existingUrl,
                    'planned_url' => $canonical,
                ];
                $report['native_overwrites_planned']++;
                continue;
            }
            if ($existingProvider !== null && $existingProvider !== '' && $existingProvider !== $provider) {
                $report['conflicts'][] = [
                    'type' => 'LESSON_VIDEO_PROVIDER_CONFLICT',
                    'lesson_id' => $lesson->id,
                    'existing_provider' => $existingProvider,
                    'planned_provider' => $provider,
                ];
                continue;
            }
            if ($existingUrl === $canonical && $existingProvider === $provider) {
                continue;
            }

            if ($dryRun) {
                $report['lesson_videos_to_change']++;
                continue;
            }

            $beforeUrl = $lesson->video_url;
            $beforeProvider = $lesson->video_provider;
            $lesson->video_url = $canonical;
            $lesson->video_provider = $provider;
            $lesson->save();
            $ownership['lesson_video_changes'][] = [
                'lesson_id' => $lesson->id,
                'legacy_lesson_id' => $legacyId,
                'before_url' => $beforeUrl,
                'before_provider' => $beforeProvider,
                'after_url' => $canonical,
                'after_provider' => $provider,
                'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
            ];
            $report['lesson_video_imported']++;
            $report['wrote_to_database'] = true;
            $report['db_writes']++;

            $this->maps->upsertMapping('media_lesson_video', 'lesson:'.$legacyId, [
                'local_id' => $lesson->id,
                'migration_run_id' => $migrationRunId,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                    'kind' => 'lesson_video',
                    'before_url' => $beforeUrl,
                    'before_provider' => $beforeProvider,
                    'after_url' => $canonical,
                    'after_provider' => $provider,
                ]),
            ]);
        }

        // ---- Phase E: topic video ----
        foreach ($videos['entities'] ?? [] as $entity) {
            if (($entity['entity_type'] ?? '') !== 'topic') {
                continue;
            }
            if (! in_array($entity['resolution_status'] ?? '', ['ONE_CLEAR_VIDEO', 'MULTIPLE_REFERENCES_SAME_VIDEO'], true)) {
                continue;
            }
            $legacyTopicId = (int) ($entity['legacy_entity_id'] ?? 0);
            $stagingLocalId = (int) ($entity['local_entity_id'] ?? 0);
            $resolved = $this->ownership->resolve($migrationRunId, 'topic', (string) $legacyTopicId);
            if ($resolved['status'] !== WordPressMediaOwnershipResolver::STATUS_OK) {
                $report['unresolved_targets']++;
                $report['deferred'][] = [
                    'reason' => $resolved['code'] ?? $resolved['status'],
                    'entity_type' => 'topic',
                    'legacy_entity_id' => $legacyTopicId,
                    'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
                ];
                continue;
            }
            $topicLocalId = (int) $resolved['local_id'];
            if ($stagingLocalId > 0 && $stagingLocalId !== $topicLocalId) {
                $report['staging_destination_mismatches']++;
            }
            $topic = Topic::query()->find($topicLocalId);
            if ($topic === null) {
                $report['conflicts'][] = ['type' => 'MISSING_TOPIC', 'local_id' => $topicLocalId];
                continue;
            }
            $canonical = (string) ($entity['canonical_video_url'] ?? '');
            $provider = (string) ($entity['provider'] ?? 'youtube');
            $report['topic_videos_planned']++;
            $existingUrl = $topic->video_url;
            $existingProvider = $topic->video_provider;
            if ($existingUrl !== null && $existingUrl !== '' && $existingUrl !== $canonical) {
                $report['conflicts'][] = [
                    'type' => 'TOPIC_VIDEO_CONFLICT',
                    'topic_id' => $topic->id,
                    'existing_url' => $existingUrl,
                    'planned_url' => $canonical,
                ];
                $report['native_overwrites_planned']++;
                continue;
            }
            if ($existingProvider !== null && $existingProvider !== '' && ! in_array($existingProvider, [$provider, 'youtube'], true) && $existingUrl !== $canonical) {
                // Allow empty provider fill; conflict on divergent non-empty
                if ($existingUrl !== null && $existingUrl !== '' && $existingUrl !== $canonical) {
                    $report['conflicts'][] = [
                        'type' => 'TOPIC_VIDEO_PROVIDER_CONFLICT',
                        'topic_id' => $topic->id,
                    ];
                    continue;
                }
            }
            if ($existingUrl === $canonical && ($existingProvider === $provider || $existingProvider === 'youtube')) {
                continue;
            }
            // If topic already has identical URL with different provider label, only fill provider when empty
            if ($existingUrl === $canonical && $existingProvider !== null && $existingProvider !== '') {
                continue;
            }

            if ($dryRun) {
                $report['topic_videos_to_change']++;
                continue;
            }

            $beforeUrl = $topic->video_url;
            $beforeProvider = $topic->video_provider;
            if ($beforeUrl === null || $beforeUrl === '') {
                $topic->video_url = $canonical;
            }
            if ($beforeProvider === null || $beforeProvider === '') {
                $topic->video_provider = $provider;
            }
            $topic->save();
            $ownership['topic_video_changes'][] = [
                'topic_id' => $topic->id,
                'legacy_topic_id' => $legacyTopicId,
                'before_url' => $beforeUrl,
                'before_provider' => $beforeProvider,
                'after_url' => $topic->video_url,
                'after_provider' => $topic->video_provider,
                'staging_local_id_ignored' => $stagingLocalId > 0 ? $stagingLocalId : null,
            ];
            $report['topic_video_imported']++;
            $report['wrote_to_database'] = true;
            $report['db_writes']++;

            $this->maps->upsertMapping('media_topic_video', 'topic:'.$legacyTopicId, [
                'local_id' => $topic->id,
                'migration_run_id' => $migrationRunId,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                    'kind' => 'topic_video',
                    'before_url' => $beforeUrl,
                    'before_provider' => $beforeProvider,
                    'after_url' => $topic->video_url,
                    'after_provider' => $topic->video_provider,
                ]),
            ]);
        }

        // Course-level video: explicitly deferred (legacy 8515 / COURSE_VIDEO_TARGET_GAP).
        $report['course_video_deferred'] = 0;
        foreach ($videos['entities'] ?? [] as $entity) {
            if (($entity['entity_type'] ?? '') !== 'course') {
                continue;
            }
            if (($entity['resolution_status'] ?? '') === 'COURSE_VIDEO_TARGET_GAP'
                || (int) ($entity['legacy_entity_id'] ?? 0) === 8515) {
                $report['course_video_deferred']++;
                $report['deferred'][] = [
                    'reason' => 'COURSE_VIDEO_TARGET_GAP',
                    'entity_type' => 'course',
                    'legacy_entity_id' => (int) ($entity['legacy_entity_id'] ?? 0),
                    'staging_local_id_ignored' => (int) ($entity['local_entity_id'] ?? 0) ?: null,
                ];
            }
        }
        if ($report['course_video_deferred'] === 0) {
            $report['course_video_deferred'] = (int) ($videos['course_level_video_entities'] ?? 1);
        }

        // ---- Phase F: inline store + rewrite ----
        $urlMap = []; // old url variants => public storage url path
        foreach ($inline['items'] ?? [] as $inlineItem) {
            $aid = (int) ($inlineItem['legacy_attachment_id'] ?? 0);
            $relPath = (string) ($inlineItem['relative_path'] ?? '');
            $plan = $this->planner->inlineImagePlan($aid, $relPath);
            $relative = $plan['planned_relative_path'];
            $publicUrl = Storage::disk('public')->url($relative);
            // Also relative /storage/... form
            $storagePath = '/storage/'.$relative;

            foreach ($inlineItem['old_wordpress_urls'] ?? [] as $old) {
                if (is_string($old) && $old !== '') {
                    $urlMap[$old] = $storagePath;
                    // decoded path variant without encoding
                    $decoded = preg_replace_callback('/%[0-9A-Fa-f]{2}/', static fn ($m) => chr(hexdec($m[0])), $old) ?? $old;
                    $urlMap[$decoded] = $storagePath;
                }
            }
            // Unencoded uploads URL
            $urlMap['https://sciencestreetlab.com/wp-content/uploads/'.$relPath] = $storagePath;
            $urlMap['http://sciencestreetlab.com/wp-content/uploads/'.$relPath] = $storagePath;

            $src = $transferred[$aid]['target_path'] ?? null;
            $destAbs = Storage::disk('public')->path($relative);
            if ($dryRun) {
                if (! is_file($destAbs)) {
                    // count as needing store
                }
                continue;
            }
            if (! is_string($src) || ! is_file($src)) {
                $report['failures'][] = ['error' => "Missing inline file aid={$aid}"];
                $report['unresolved_rewrites']++;
                continue;
            }
            $destDir = dirname($destAbs);
            if (! is_dir($destDir) && ! mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
                throw new RuntimeException("Cannot create {$destDir}");
            }
            if (! is_file($destAbs)) {
                if (! copy($src, $destAbs)) {
                    throw new RuntimeException("Failed copying inline image {$destAbs}");
                }
                $ownership['physical_paths'][] = $destAbs;
                $report['live_file_writes']++;
            }
            $report['inline_images_stored']++;
        }

        // Rewrite lesson content — destination ONLY via Run-scoped LESSON maps (never staging local_entity_id).
        $lessonsTouched = [];
        $skippedUnimportedLessons = [];
        foreach ($inline['items'] ?? [] as $inlineItem) {
            $report['inline_replacements_planned']++;
            foreach ($inlineItem['affected_entities'] ?? [] as $ent) {
                if (($ent['entity_type'] ?? '') !== 'lesson') {
                    continue;
                }
                $stagingLocalId = (int) ($ent['local_entity_id'] ?? 0);
                $legacyLessonId = (int) ($ent['legacy_entity_id'] ?? 0);
                if ($legacyLessonId <= 0) {
                    continue;
                }
                $resolved = $this->ownership->resolve($migrationRunId, 'lesson', (string) $legacyLessonId);
                if ($resolved['status'] !== WordPressMediaOwnershipResolver::STATUS_OK) {
                    $skippedUnimportedLessons[$legacyLessonId] = true;
                    $report['unresolved_targets']++;
                    continue;
                }
                $lessonId = (int) $resolved['local_id'];
                if ($stagingLocalId > 0 && $stagingLocalId !== $lessonId) {
                    $report['staging_destination_mismatches']++;
                }
                $lessonsTouched[$lessonId] = true;
            }
        }
        $report['inline_lessons_unimported_skipped'] = count($skippedUnimportedLessons);

        foreach (array_keys($lessonsTouched) as $lessonId) {
            $lesson = Lesson::query()->find($lessonId);
            if ($lesson === null) {
                $report['conflicts'][] = ['type' => 'MISSING_LESSON_FOR_INLINE', 'lesson_id' => $lessonId];
                $report['unresolved_rewrites']++;
                continue;
            }

            $translations = $lesson->getTranslations('content');
            if ($translations === []) {
                // raw attribute may be string/json
                $raw = $lesson->getAttributes()['content'] ?? null;
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    $translations = is_array($decoded) ? $decoded : ['ar' => $raw];
                }
            }

            $changedLocales = [];
            $newTranslations = $translations;
            foreach ($translations as $locale => $html) {
                if (! is_string($html) || $html === '') {
                    continue;
                }
                $replaced = $this->replaceApprovedUrls($html, $urlMap, $replacementCount);
                if ($replaced !== $html) {
                    if ($dryRun) {
                        $report['url_replacements'] += $replacementCount;
                        $report['content_fields_changed']++;
                    } else {
                        $beforeHash = hash('sha256', $html);
                        $newTranslations[$locale] = $replaced;
                        $changedLocales[$locale] = [
                            'before_hash' => $beforeHash,
                            'after_hash' => hash('sha256', $replaced),
                            'before_content' => $html,
                            'replacements' => $replacementCount,
                        ];
                        $report['url_replacements'] += $replacementCount;
                        $report['content_fields_changed']++;
                    }
                }
            }

            if ($dryRun) {
                if ($report['content_fields_changed'] > 0) {
                    // lessons_rewritten counted after loop uniquely
                }
                continue;
            }

            if ($changedLocales !== []) {
                foreach ($changedLocales as $locale => $meta) {
                    $lesson->setTranslation('content', $locale, $newTranslations[$locale]);
                }
                $lesson->save();
                $ownership['content_rewrites'][] = [
                    'lesson_id' => $lesson->id,
                    'locales' => $changedLocales,
                ];
                $report['lessons_rewritten']++;
                $report['wrote_to_database'] = true;
                $report['db_writes']++;

                $this->maps->upsertMapping('media_inline_rewrite', 'lesson:'.$lesson->id, [
                    'local_id' => $lesson->id,
                    'migration_run_id' => $migrationRunId,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                        'kind' => 'lesson_content_rewrite',
                        'locales' => array_map(static fn (array $m): array => [
                            'before_hash' => $m['before_hash'],
                            'after_hash' => $m['after_hash'],
                            'replacements' => $m['replacements'],
                            // full content stored in ownership journal for rollback
                        ], $changedLocales),
                    ]),
                ]);
            }
        }

        if ($dryRun) {
            $report['lessons_rewritten'] = count($lessonsTouched); // expected candidates
            // Recompute would-rewrite more accurately
            $would = 0;
            foreach (array_keys($lessonsTouched) as $lessonId) {
                $lesson = Lesson::query()->find($lessonId);
                if (! $lesson) {
                    continue;
                }
                foreach ($lesson->getTranslations('content') as $html) {
                    if (! is_string($html)) {
                        continue;
                    }
                    $tmp = 0;
                    $out = $this->replaceApprovedUrls($html, $urlMap, $tmp);
                    if ($out !== $html) {
                        $would++;
                        break;
                    }
                }
            }
            $report['lessons_rewritten'] = $would;
            $report['inline_images_stored'] = count($inline['items'] ?? []);
        }

        if (! $dryRun) {
            $this->writeOwnership($migrationRunId, $ownership);
        }

        // Idempotency-oriented counters for second dry-run
        if (! $dryRun) {
            $report['files_to_download'] = 0;
            $report['product_media_to_attach'] = 0;
            $report['course_images_to_change'] = 0;
            $report['lesson_videos_to_change'] = 0;
            $report['topic_videos_to_change'] = 0;
        }

        if ($report['conflicts'] !== []) {
            $report['status'] = $dryRun ? 'dry_run_with_conflicts' : 'executed_with_conflicts';
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function transferOne(array $item, int $runId, bool $dryRun, string $workRoot): array
    {
        $aid = (int) $item['legacy_attachment_id'];
        $relative = (string) $item['relative_path'];
        $this->localSource->assertSafeRelativePath($relative);

        $basename = basename(str_replace('\\', '/', $relative));
        $targetPath = rtrim($workRoot, '/').'/files/'.$aid.'/'.$basename;
        $metaPath = rtrim($workRoot, '/').'/files/'.$aid.'/transfer.json';

        if (is_file($targetPath) && is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true) ?: [];
            $verify = $this->localSource->verifyFile($aid, $basename, $targetPath);
            if ($verify['ok'] && ($meta['sha256'] ?? null) === $verify['sha256']) {
                return [
                    'status' => 'reused',
                    'legacy_attachment_id' => $aid,
                    'relative_path' => $relative,
                    'expected_mime' => $item['mime'] ?? null,
                    'actual_bytes' => $verify['actual_bytes'],
                    'sha256' => $verify['sha256'],
                    'target_path' => $targetPath,
                    'migration_run_id' => $runId,
                    'code' => null,
                ];
            }
        }

        $sourcePath = $this->localSource->resolveSourceFile($aid, $basename);
        if ($sourcePath === null) {
            if ($this->localSource->allowHttp()) {
                return [
                    'status' => 'failed',
                    'legacy_attachment_id' => $aid,
                    'error' => 'HTTP fallback is disabled for historical M2 media (local source missing).',
                    'code' => 'HTTP_FALLBACK_FORBIDDEN',
                    'source_url' => $item['canonical_source_url'] ?? null,
                ];
            }

            return [
                'status' => 'failed',
                'legacy_attachment_id' => $aid,
                'error' => 'Missing local source file',
                'code' => 'MISSING_LOCAL_SOURCE',
                'relative_path' => $relative,
            ];
        }

        $verify = $this->localSource->verifyFile($aid, $basename, $sourcePath);
        if (! $verify['ok']) {
            return [
                'status' => 'failed',
                'legacy_attachment_id' => $aid,
                'error' => $verify['code'] ?? 'VERIFY_FAILED',
                'code' => $verify['code'],
                'expected_sha256' => $verify['expected_sha256'],
                'sha256' => $verify['sha256'],
                'actual_bytes' => $verify['actual_bytes'],
                'source_path' => $sourcePath,
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'would_use_local',
                'legacy_attachment_id' => $aid,
                'relative_path' => $relative,
                'expected_mime' => $item['mime'] ?? null,
                'expected_length' => $item['content_length'] ?? null,
                'actual_bytes' => $verify['actual_bytes'],
                'sha256' => $verify['sha256'],
                'source_path' => $sourcePath,
                'target_path' => $targetPath,
                'migration_run_id' => $runId,
                'code' => null,
            ];
        }

        $dir = dirname($targetPath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        if (! copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Failed copying local media source to {$targetPath}");
        }

        $record = [
            'status' => 'copied_local',
            'legacy_attachment_id' => $aid,
            'source_path' => $sourcePath,
            'source_url' => $item['canonical_source_url'] ?? null,
            'relative_path' => $relative,
            'expected_mime' => $item['mime'] ?? null,
            'actual_mime' => $item['mime'] ?? null,
            'expected_length' => $item['content_length'] ?? null,
            'actual_bytes' => $verify['actual_bytes'],
            'sha256' => $verify['sha256'],
            'target_path' => $targetPath,
            'migration_run_id' => $runId,
            'http_used' => false,
            'code' => null,
        ];
        file_put_contents($metaPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $record;
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    private function replaceApprovedUrls(string $html, array $urlMap, ?int &$count = null): string
    {
        $count = 0;
        // Longest keys first to avoid partial overlaps
        $keys = array_keys($urlMap);
        usort($keys, static fn ($a, $b) => strlen($b) <=> strlen($a));
        $out = $html;
        foreach ($keys as $old) {
            if ($old === '') {
                continue;
            }
            $new = $urlMap[$old];
            if (str_contains($out, $old)) {
                $n = 0;
                $out = str_replace($old, $new, $out, $n);
                $count += $n;
            }
            // JSON-escaped slashes variant (\/)
            $oldEsc = str_replace('/', '\\/', $old);
            $newEsc = str_replace('/', '\\/', $new);
            if ($oldEsc !== $old && str_contains($out, $oldEsc)) {
                $n = 0;
                $out = str_replace($oldEsc, $newEsc, $out, $n);
                $count += $n;
            }
        }

        return $out;
    }

    private function workRoot(int $runId): string
    {
        return storage_path('app/private/migration/wordpress-media/'.$runId);
    }

    /**
     * @param  array<string, mixed>  $ownership
     */
    private function writeOwnership(int $runId, array $ownership): void
    {
        $dir = $this->workRoot($runId);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create ownership dir {$dir}");
        }
        file_put_contents(
            $dir.'/ownership.json',
            json_encode($ownership, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Missing artifact: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException("Invalid JSON: {$path}");
        }

        return $data;
    }
}
