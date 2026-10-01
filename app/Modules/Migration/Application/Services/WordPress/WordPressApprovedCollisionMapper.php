<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;

/**
 * Explicit MAP_EXISTING collision registry for staging preparation.
 *
 * Dry-run simulation never writes. apply() only upserts legacy_import_maps with
 * mapped_to_existing ownership — never creates/updates/deletes catalog rows.
 *
 * Course 8507 is HUMAN_APPROVED_FOR_STAGING as MAP_EXISTING + ABSORB_TREE
 * (Option A MAP_EXISTING_AND_IMPORT_CONTENT).
 */
final class WordPressApprovedCollisionMapper
{
    public const DECISION_MAP_EXISTING = 'MAP_EXISTING';

    public const STATUS_SKIPPED_VARIABLE = 'SKIPPED_VARIABLE_PRODUCT';

    public const STATUS_UNRESOLVED = 'UNRESOLVED';

    public function __construct(
        private readonly LegacyImportMapRepository $maps,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedProductEntries(): array
    {
        $entries = config('wordpress.approved_map_existing.products', []);

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedCompetitionEntries(): array
    {
        $entries = config('wordpress.approved_map_existing.competitions', []);

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedCourseEntries(): array
    {
        $entries = config('wordpress.approved_map_existing.courses', []);

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @return list<int|string>
     */
    public function skippedVariableProductIds(): array
    {
        $ids = config('wordpress.skip_variable_product_ids', [6912]);

        return is_array($ids) ? $ids : [6912];
    }

    /**
     * Effective MAP_EXISTING only when the approved destination target exists.
     *
     * Staging registry entries remain configured historically; on destinations
     * where the local slug is absent, return null so importers fall through to
     * normal create/collision logic (no phantom would_map_existing).
     */
    public function productDecision(string $legacyId): ?string
    {
        foreach ($this->approvedProductEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') !== $legacyId
                || ($entry['decision'] ?? null) !== self::DECISION_MAP_EXISTING) {
                continue;
            }

            $slug = $entry['local_slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                return null;
            }

            return Product::query()->where('slug', $slug)->exists()
                ? self::DECISION_MAP_EXISTING
                : null;
        }

        return null;
    }

    public function competitionDecision(string $legacyId): ?string
    {
        foreach ($this->approvedCompetitionEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') !== $legacyId
                || ($entry['decision'] ?? null) !== self::DECISION_MAP_EXISTING) {
                continue;
            }

            $slug = $entry['local_slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                return null;
            }

            return Competition::query()->where('slug', $slug)->exists()
                ? self::DECISION_MAP_EXISTING
                : null;
        }

        return null;
    }

    public function courseDecision(string $legacyId): ?string
    {
        foreach ($this->approvedCourseEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') !== $legacyId
                || ($entry['decision'] ?? null) !== self::DECISION_MAP_EXISTING) {
                continue;
            }

            $slug = $entry['local_slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                return null;
            }

            return Course::query()->where('slug', $slug)->exists()
                ? self::DECISION_MAP_EXISTING
                : null;
        }

        return null;
    }

    public function approvedProductLocalSlug(string $legacyId): ?string
    {
        foreach ($this->approvedProductEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') === $legacyId) {
                $slug = $entry['local_slug'] ?? null;

                return is_string($slug) && $slug !== '' ? $slug : null;
            }
        }

        return null;
    }

    public function approvedCompetitionLocalSlug(string $legacyId): ?string
    {
        foreach ($this->approvedCompetitionEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') === $legacyId) {
                $slug = $entry['local_slug'] ?? null;

                return is_string($slug) && $slug !== '' ? $slug : null;
            }
        }

        return null;
    }

    public function approvedCourseLocalSlug(string $legacyId): ?string
    {
        foreach ($this->approvedCourseEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') === $legacyId) {
                $slug = $entry['local_slug'] ?? null;

                return is_string($slug) && $slug !== '' ? $slug : null;
            }
        }

        return null;
    }

    public function courseTreePolicy(string $legacyId): ?string
    {
        foreach ($this->approvedCourseEntries() as $entry) {
            if ((string) ($entry['legacy_id'] ?? '') === $legacyId) {
                $policy = $entry['tree_policy'] ?? null;
                if (is_string($policy) && $policy !== '') {
                    return $policy;
                }
            }
        }

        $policies = config('wordpress.course_tree_policies', []);
        if (is_array($policies) && isset($policies[$legacyId]) && is_string($policies[$legacyId])) {
            return $policies[$legacyId];
        }

        return null;
    }

    /**
     * Simulate applying approved MAP_EXISTING rows (no database writes).
     *
     * @return array<string, mixed>
     */
    public function simulate(): array
    {
        return $this->run(true);
    }

    /**
     * Upsert legacy_import_maps only. Never mutates product/course/competition rows.
     *
     * @return array<string, mixed>
     */
    public function apply(): array
    {
        return $this->run(false);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(bool $dryRun): array
    {
        $products = [];
        $wouldMapProducts = 0;
        $alreadyMappedProducts = 0;
        $unresolvedProducts = 0;
        $mappedProducts = 0;

        foreach ($this->approvedProductEntries() as $entry) {
            $legacyId = (string) ($entry['legacy_id'] ?? '');
            $localSlug = (string) ($entry['local_slug'] ?? '');
            $row = [
                'entity_type' => 'product',
                'legacy_id' => $legacyId,
                'local_slug' => $localSlug,
                'decision' => self::DECISION_MAP_EXISTING,
                'note' => $entry['note'] ?? null,
            ];

            $existingMap = $this->maps->find('product', $legacyId);
            if ($existingMap?->local_id) {
                $row['status'] = 'SKIPPED_MAPPED';
                $row['local_id'] = $existingMap->local_id;
                $alreadyMappedProducts++;
                $products[] = $row;

                continue;
            }

            $local = $localSlug !== ''
                ? Product::query()->where('slug', $localSlug)->first(['id', 'slug', 'sku'])
                : null;

            if ($local === null) {
                $row['status'] = self::STATUS_UNRESOLVED;
                $row['code'] = 'LOCAL_SLUG_NOT_FOUND';
                $unresolvedProducts++;
                $products[] = $row;

                continue;
            }

            $row['local_id'] = $local->id;
            $row['local_sku'] = $local->sku;
            $row['status'] = $dryRun ? MigrationImportOutcome::WOULD_MAP_EXISTING : 'MAPPED_EXISTING';
            $row['ownership'] = [
                'created_by_migration' => false,
                'mapped_to_existing' => true,
                'overwrite' => false,
            ];

            if (! $dryRun) {
                $this->maps->upsertMapping('product', $legacyId, [
                    'local_id' => $local->id,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                        'collision_decision' => self::DECISION_MAP_EXISTING,
                        'overwrite' => false,
                        'local_slug' => $localSlug,
                        'note' => $entry['note'] ?? 'approved_map_existing',
                        'approval_source' => 'config:wordpress.approved_map_existing.products',
                    ]),
                ]);
                $mappedProducts++;
            } else {
                $wouldMapProducts++;
            }

            $products[] = $row;
        }

        $competitions = [];
        $wouldMapCompetitions = 0;
        $alreadyMappedCompetitions = 0;
        $unresolvedCompetitions = 0;
        $mappedCompetitions = 0;

        foreach ($this->approvedCompetitionEntries() as $entry) {
            $legacyId = (string) ($entry['legacy_id'] ?? '');
            $localSlug = (string) ($entry['local_slug'] ?? '');
            $row = [
                'entity_type' => 'competition',
                'legacy_id' => $legacyId,
                'local_slug' => $localSlug,
                'decision' => self::DECISION_MAP_EXISTING,
                'note' => $entry['note'] ?? null,
                'rollback_policy' => 'unmap_only_never_delete_competition',
            ];

            $existingMap = $this->maps->find('competition', $legacyId);
            if ($existingMap?->local_id) {
                $row['status'] = 'SKIPPED_MAPPED';
                $row['local_id'] = $existingMap->local_id;
                $alreadyMappedCompetitions++;
                $competitions[] = $row;

                continue;
            }

            $local = $localSlug !== ''
                ? Competition::query()->where('slug', $localSlug)->first(['id', 'slug', 'status'])
                : null;

            if ($local === null) {
                $row['status'] = self::STATUS_UNRESOLVED;
                $row['code'] = 'LOCAL_SLUG_NOT_FOUND';
                $unresolvedCompetitions++;
                $competitions[] = $row;

                continue;
            }

            $row['local_id'] = $local->id;
            $row['local_status'] = $local->status;
            $row['status'] = $dryRun ? MigrationImportOutcome::WOULD_MAP_EXISTING : 'MAPPED_EXISTING';
            $row['ownership'] = [
                'created_by_migration' => false,
                'mapped_to_existing' => true,
                'overwrite' => false,
            ];

            if (! $dryRun) {
                $this->maps->upsertMapping('competition', $legacyId, [
                    'local_id' => $local->id,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                        'collision_decision' => self::DECISION_MAP_EXISTING,
                        'overwrite' => false,
                        'local_slug' => $localSlug,
                        'note' => $entry['note'] ?? 'approved_map_existing',
                        'approval_source' => 'config:wordpress.approved_map_existing.competitions',
                        'rollback_policy' => 'unmap_only_never_delete_competition',
                    ]),
                ]);
                $mappedCompetitions++;
            } else {
                $wouldMapCompetitions++;
            }

            $competitions[] = $row;
        }

        $courses = [];
        $wouldMapCourses = 0;
        $alreadyMappedCourses = 0;
        $unresolvedCourses = 0;
        $mappedCourses = 0;

        foreach ($this->approvedCourseEntries() as $entry) {
            $legacyId = (string) ($entry['legacy_id'] ?? '');
            $localSlug = (string) ($entry['local_slug'] ?? '');
            $treePolicy = is_string($entry['tree_policy'] ?? null)
                ? $entry['tree_policy']
                : $this->courseTreePolicy($legacyId);
            $row = [
                'entity_type' => 'course',
                'legacy_id' => $legacyId,
                'local_slug' => $localSlug,
                'decision' => self::DECISION_MAP_EXISTING,
                'tree_policy' => $treePolicy,
                'note' => $entry['note'] ?? null,
                'rollback_policy' => 'unmap_only_never_delete_course',
            ];

            $existingMap = $this->maps->find('course', $legacyId);
            if ($existingMap?->local_id) {
                $row['status'] = 'SKIPPED_MAPPED';
                $row['local_id'] = $existingMap->local_id;
                $alreadyMappedCourses++;
                $courses[] = $row;

                continue;
            }

            $local = $localSlug !== ''
                ? Course::query()->where('slug', $localSlug)->first(['id', 'slug'])
                : null;

            if ($local === null) {
                $row['status'] = self::STATUS_UNRESOLVED;
                $row['code'] = 'LOCAL_SLUG_NOT_FOUND';
                $unresolvedCourses++;
                $courses[] = $row;

                continue;
            }

            $row['local_id'] = $local->id;
            $row['status'] = $dryRun ? MigrationImportOutcome::WOULD_MAP_EXISTING : 'MAPPED_EXISTING';
            $row['ownership'] = [
                'created_by_migration' => false,
                'mapped_to_existing' => true,
                'overwrite' => false,
            ];

            if (! $dryRun) {
                $this->maps->upsertMapping('course', $legacyId, [
                    'local_id' => $local->id,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                        'collision_decision' => self::DECISION_MAP_EXISTING,
                        'overwrite' => false,
                        'local_slug' => $localSlug,
                        'tree_policy' => $treePolicy,
                        'note' => $entry['note'] ?? 'approved_map_existing',
                        'approval_source' => 'config:wordpress.approved_map_existing.courses',
                        'rollback_policy' => 'unmap_only_never_delete_course',
                    ]),
                ]);
                $mappedCourses++;
            } else {
                $wouldMapCourses++;
            }

            $courses[] = $row;
        }

        $skippedVariable = [];
        foreach ($this->skippedVariableProductIds() as $id) {
            $skippedVariable[] = [
                'legacy_id' => (string) $id,
                'status' => self::STATUS_SKIPPED_VARIABLE,
                'decision' => 'SKIP',
                'note' => 'First staging run excludes variable products; do not flatten or MAP_EXISTING.',
            ];
        }

        $course8507Approved = $this->courseDecision('8507') === self::DECISION_MAP_EXISTING;

        return [
            'status' => $dryRun ? 'dry_run' : 'applied',
            'dry_run' => $dryRun,
            'wrote_to_database' => ! $dryRun && ($mappedProducts + $mappedCompetitions + $mappedCourses) > 0,
            'policy' => [
                'map_only' => true,
                'overwrite_commercial_data' => false,
                'created_by_migration' => false,
                'mapped_to_existing' => true,
                'rollback' => 'removes_legacy_import_maps_only_for_mapped_existing',
            ],
            'products' => [
                'approved_count' => count($this->approvedProductEntries()),
                'would_map_existing' => $wouldMapProducts,
                'already_skipped_mapped' => $alreadyMappedProducts,
                'mapped' => $mappedProducts,
                'unresolved' => $unresolvedProducts,
                'rows' => $products,
            ],
            'competitions' => [
                'approved_count' => count($this->approvedCompetitionEntries()),
                'would_map_existing' => $wouldMapCompetitions,
                'already_skipped_mapped' => $alreadyMappedCompetitions,
                'mapped' => $mappedCompetitions,
                'unresolved' => $unresolvedCompetitions,
                'rows' => $competitions,
            ],
            'courses' => [
                'approved_count' => count($this->approvedCourseEntries()),
                'would_map_existing' => $wouldMapCourses,
                'already_skipped_mapped' => $alreadyMappedCourses,
                'mapped' => $mappedCourses,
                'unresolved' => $unresolvedCourses,
                'note' => $course8507Approved
                    ? 'Course 8507 HUMAN_APPROVED_FOR_STAGING: MAP_EXISTING + ABSORB_TREE (MAP_EXISTING_AND_IMPORT_CONTENT).'
                    : 'Course 8507 unresolved.',
                'rows' => $courses,
            ],
            'skipped_variable_products' => $skippedVariable,
            'course_8507' => [
                'verdict' => config('wordpress.course_8507_verdict'),
                'approved_for_map_existing' => $course8507Approved,
                'tree_policy' => $this->courseTreePolicy('8507'),
                'human_approved_for_staging' => $course8507Approved,
                'docs' => 'docs/WORDPRESS-COURSE-8507-IDENTITY.md',
            ],
        ];
    }
}
