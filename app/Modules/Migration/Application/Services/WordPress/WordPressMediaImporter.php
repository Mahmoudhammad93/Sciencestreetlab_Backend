<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Controlled WordPress media M2 import.
 *
 * Dry-run by default. Real mutation requires REAL_PERSIST + active MEDIA_RUN_ID.
 * Processes ONLY the 148-file M1 transfer manifest (+ video/inline plans).
 */
final class WordPressMediaImporter
{
    public const SKIPPED_LEGACY_PRODUCT_ID = 6912;

    private const MANIFEST = 'docs/media-m1/media-transfer-manifest.json';

    private const VIDEO_RESOLUTION = 'docs/media-m1/entity-video-resolution.json';

    private const INLINE_PLAN = 'docs/media-m1/inline-image-rewrite-plan.json';

    private const ALLOWED_HOSTS = ['sciencestreetlab.com', 'www.sciencestreetlab.com'];

    public function __construct(
        private readonly WordPressRealPersistGate $persistGate,
        private readonly MigrationRunService $runs,
        private readonly WordPressMediaTransferPlanner $planner,
        private readonly LegacyImportMapRepository $maps,
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

        $report = [
            'status' => $dryRun ? 'dry_run' : 'executed',
            'entity_type' => 'media',
            'dry_run' => $dryRun,
            'migration_run_id' => $migrationRunId,
            'wrote_to_database' => false,
            'files_expected' => 148,
            'files_downloaded' => 0,
            'files_reused' => 0,
            'files_failed' => 0,
            'bytes_downloaded' => 0,
            'files_to_download' => 0,
            'product_featured_attached' => 0,
            'product_gallery_attached' => 0,
            'product_media_to_attach' => 0,
            'product_6912_skipped_usages' => 0,
            'course_images_local' => 0,
            'course_images_to_change' => 0,
            'lesson_video_imported' => 0,
            'lesson_videos_to_change' => 0,
            'lesson_video_deferred' => 0,
            'lesson_no_video' => 0,
            'topic_video_imported' => 0,
            'topic_videos_to_change' => 0,
            'course_video_deferred' => 1,
            'inline_images_stored' => 0,
            'lessons_rewritten' => 0,
            'content_fields_changed' => 0,
            'url_replacements' => 0,
            'unresolved_rewrites' => 0,
            'conflicts' => [],
            'failures' => [],
            'skipped' => [],
            'transfer_records' => [],
        ];

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
                    $report['failures'][] = $result;
                    $transferred[$aid] = $result;
                    continue;
                }
                if ($result['status'] === 'reused') {
                    $report['files_reused']++;
                } elseif ($result['status'] === 'downloaded') {
                    $report['files_downloaded']++;
                    $report['bytes_downloaded'] += (int) ($result['actual_bytes'] ?? 0);
                } elseif ($result['status'] === 'would_download') {
                    $report['files_to_download']++;
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
                if ($legacyProductId === self::SKIPPED_LEGACY_PRODUCT_ID) {
                    $report['product_6912_skipped_usages']++;
                    $report['skipped'][] = [
                        'reason' => 'PRODUCT_6912_NOT_MIGRATED',
                        'legacy_attachment_id' => $aid,
                        'legacy_product_id' => $legacyProductId,
                        'action' => $action,
                    ];
                    $ownership['skipped_product_6912'][] = [
                        'legacy_attachment_id' => $aid,
                        'action' => $action,
                    ];
                    continue;
                }
                $localProductId = (int) ($usage['local_entity_id'] ?? 0);
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
                if ($action === 'PRODUCT_IMAGE_ATTACH') {
                    $report['product_featured_attached']++;
                } else {
                    $report['product_gallery_attached']++;
                }
                $report['wrote_to_database'] = true;

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
                $localCourseId = (int) ($usage['local_entity_id'] ?? 0);
                $course = Course::query()->find($localCourseId);
                if ($course === null) {
                    $report['conflicts'][] = [
                        'type' => 'MISSING_COURSE',
                        'local_course_id' => $localCourseId,
                        'legacy_attachment_id' => $aid,
                    ];
                    continue;
                }
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
                if (! copy($src, $destAbs)) {
                    throw new RuntimeException("Failed copying course image to {$destAbs}");
                }
                $before = $course->image_url;
                $course->image_url = $relative;
                $course->save();
                $ownership['course_image_changes'][] = [
                    'course_id' => $course->id,
                    'before' => $before,
                    'after' => $relative,
                    'absolute_path' => $destAbs,
                ];
                $ownership['physical_paths'][] = $destAbs;
                $report['course_images_local']++;
                $report['wrote_to_database'] = true;

                $this->maps->upsertMapping('media_course_image', 'course:'.(int) $usage['legacy_entity_id'].':'.$aid, [
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
            $localId = (int) ($entity['local_entity_id'] ?? 0);

            if ($status === 'NO_VIDEO') {
                $report['lesson_no_video']++;
                continue;
            }
            if ($status === 'MULTIPLE_DISTINCT_VIDEOS' || $legacyId === 8701) {
                $report['lesson_video_deferred']++;
                continue;
            }
            if (! in_array($status, ['ONE_CLEAR_VIDEO', 'MULTIPLE_REFERENCES_SAME_VIDEO'], true)) {
                $report['lesson_video_deferred']++;
                continue;
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

            $existingUrl = $lesson->video_url;
            $existingProvider = $lesson->video_provider;
            if ($existingUrl !== null && $existingUrl !== '' && $existingUrl !== $canonical) {
                $report['conflicts'][] = [
                    'type' => 'LESSON_VIDEO_CONFLICT',
                    'lesson_id' => $lesson->id,
                    'existing_url' => $existingUrl,
                    'planned_url' => $canonical,
                ];
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
                'before_url' => $beforeUrl,
                'before_provider' => $beforeProvider,
                'after_url' => $canonical,
                'after_provider' => $provider,
            ];
            $report['lesson_video_imported']++;
            $report['wrote_to_database'] = true;

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
            $topic = Topic::query()->find((int) ($entity['local_entity_id'] ?? 0));
            if ($topic === null) {
                $report['conflicts'][] = ['type' => 'MISSING_TOPIC', 'local_id' => $entity['local_entity_id'] ?? null];
                continue;
            }
            $canonical = (string) ($entity['canonical_video_url'] ?? '');
            $provider = (string) ($entity['provider'] ?? 'youtube');
            $existingUrl = $topic->video_url;
            $existingProvider = $topic->video_provider;
            if ($existingUrl !== null && $existingUrl !== '' && $existingUrl !== $canonical) {
                $report['conflicts'][] = [
                    'type' => 'TOPIC_VIDEO_CONFLICT',
                    'topic_id' => $topic->id,
                    'existing_url' => $existingUrl,
                    'planned_url' => $canonical,
                ];
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
                'before_url' => $beforeUrl,
                'before_provider' => $beforeProvider,
                'after_url' => $topic->video_url,
                'after_provider' => $topic->video_provider,
            ];
            $report['topic_video_imported']++;
            $report['wrote_to_database'] = true;

            $this->maps->upsertMapping('media_topic_video', 'topic:'.(int) $entity['legacy_entity_id'], [
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

        // Course-level video: explicitly deferred
        $report['course_video_deferred'] = 1;

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
            }
            $report['inline_images_stored']++;
        }

        // Rewrite lesson content (resolve local ids via import maps when plan omits them)
        $lessonsTouched = [];
        $skippedUnimportedLessons = [];
        foreach ($inline['items'] ?? [] as $inlineItem) {
            foreach ($inlineItem['affected_entities'] ?? [] as $ent) {
                if (($ent['entity_type'] ?? '') !== 'lesson') {
                    continue;
                }
                $lessonId = (int) ($ent['local_entity_id'] ?? 0);
                $legacyLessonId = (int) ($ent['legacy_entity_id'] ?? 0);
                if ($lessonId <= 0 && $legacyLessonId > 0) {
                    $map = $this->maps->find('lesson', (string) $legacyLessonId);
                    $lessonId = (int) ($map?->local_id ?? 0);
                }
                if ($lessonId <= 0) {
                    if ($legacyLessonId > 0) {
                        $skippedUnimportedLessons[$legacyLessonId] = true;
                    }
                    continue;
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
        $this->assertSafeRelativePath($relative);

        $url = (string) $item['canonical_source_url'];
        $this->assertAllowedUrl($url);

        $basename = basename(str_replace('\\', '/', $relative));
        $targetPath = rtrim($workRoot, '/').'/files/'.$aid.'/'.$basename;
        $metaPath = rtrim($workRoot, '/').'/files/'.$aid.'/transfer.json';

        if (is_file($targetPath) && is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true) ?: [];
            $sha = hash_file('sha256', $targetPath);
            if (($meta['sha256'] ?? null) === $sha && filesize($targetPath) > 0) {
                return [
                    'status' => $dryRun ? 'reused' : 'reused',
                    'legacy_attachment_id' => $aid,
                    'source_url' => $url,
                    'relative_path' => $relative,
                    'expected_mime' => $item['mime'] ?? null,
                    'actual_mime' => $meta['actual_mime'] ?? null,
                    'expected_length' => $item['content_length'] ?? null,
                    'actual_bytes' => filesize($targetPath),
                    'sha256' => $sha,
                    'target_path' => $targetPath,
                    'migration_run_id' => $runId,
                ];
            }
        }

        if ($dryRun) {
            return [
                'status' => 'would_download',
                'legacy_attachment_id' => $aid,
                'source_url' => $url,
                'relative_path' => $relative,
                'expected_mime' => $item['mime'] ?? null,
                'expected_length' => $item['content_length'] ?? null,
                'target_path' => $targetPath,
                'migration_run_id' => $runId,
            ];
        }

        $dir = dirname($targetPath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $tmp = $targetPath.'.part';
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        $response = Http::withHeaders(['User-Agent' => 'ScienceStreetLab-M2-MediaTransfer/1.0'])
            ->withOptions([
                'sink' => $tmp,
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
            ])
            ->timeout(120)
            ->get($url);

        if (! $response->successful()) {
            @unlink($tmp);

            return [
                'status' => 'failed',
                'legacy_attachment_id' => $aid,
                'error' => 'HTTP '.$response->status(),
                'source_url' => $url,
            ];
        }

        $finalUrl = (string) ($response->effectiveUri() ?? $url);
        $this->assertAllowedUrl($finalUrl);

        if (! is_file($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);

            return [
                'status' => 'failed',
                'legacy_attachment_id' => $aid,
                'error' => 'Empty download',
                'source_url' => $url,
            ];
        }

        $actualBytes = filesize($tmp) ?: 0;
        $expected = $item['content_length'] ?? null;
        if (is_numeric($expected) && (int) $expected > 0 && (int) $expected !== $actualBytes) {
            @unlink($tmp);

            return [
                'status' => 'failed',
                'legacy_attachment_id' => $aid,
                'error' => "Content-Length mismatch expected={$expected} actual={$actualBytes}",
                'source_url' => $url,
            ];
        }

        $ctype = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $expectedMime = strtolower((string) ($item['mime'] ?? ''));
        if (str_starts_with($ctype, 'text/html')) {
            @unlink($tmp);

            return [
                'status' => 'failed',
                'legacy_attachment_id' => $aid,
                'error' => 'HTML content-type rejected',
                'source_url' => $url,
            ];
        }
        if ($expectedMime !== '' && $ctype !== '') {
            $expMain = explode('/', $expectedMime)[0] ?? '';
            $gotMain = explode('/', $ctype)[0] ?? '';
            if ($expMain !== '' && $gotMain !== '' && $expMain !== $gotMain) {
                @unlink($tmp);

                return [
                    'status' => 'failed',
                    'legacy_attachment_id' => $aid,
                    'error' => "MIME mismatch expected={$expectedMime} actual={$ctype}",
                    'source_url' => $url,
                ];
            }
        }

        $sha = hash_file('sha256', $tmp);
        if (! rename($tmp, $targetPath)) {
            @unlink($tmp);
            throw new RuntimeException("Atomic move failed for {$targetPath}");
        }

        $record = [
            'status' => 'downloaded',
            'legacy_attachment_id' => $aid,
            'source_url' => $url,
            'final_source_url' => $finalUrl,
            'relative_path' => $relative,
            'expected_mime' => $item['mime'] ?? null,
            'actual_mime' => $ctype ?: ($item['mime'] ?? null),
            'expected_length' => $item['content_length'] ?? null,
            'actual_bytes' => $actualBytes,
            'sha256' => $sha,
            'target_path' => $targetPath,
            'migration_run_id' => $runId,
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

    private function assertSafeRelativePath(string $relative): void
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('Unsafe relative path');
        }
        if (str_starts_with($relative, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $relative) === 1) {
            throw new RuntimeException('Absolute paths rejected');
        }
        $parts = explode('/', str_replace('\\', '/', $relative));
        foreach ($parts as $part) {
            if ($part === '..') {
                throw new RuntimeException('Path traversal rejected');
            }
        }
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            throw new RuntimeException("Only https URLs allowed: {$url}");
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed) {
                return;
            }
        }
        throw new RuntimeException("Disallowed source host: {$host}");
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
