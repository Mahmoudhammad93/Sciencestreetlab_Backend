<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

/**
 * Shared dry-run / real-persist outcome vocabulary.
 *
 * Dry-run and writers must classify with the same rules so predictions cannot drift.
 */
final class MigrationImportOutcome
{
    public const WOULD_CREATE = 'would_create';

    public const WOULD_MAP_EXISTING = 'would_map_existing';

    public const WOULD_SKIP = 'would_skip';

    public const WOULD_DEFER = 'would_defer';

    public const WOULD_FAIL = 'would_fail';

    /**
     * Course shell classification (slug + map), shared by dry-run and persistCourseTree gate.
     *
     * @param  'MAP_EXISTING'|'CREATE_NEW'|null  $explicitDecision
     */
    public static function classifyCourseShell(
        bool $hasLocalMap,
        bool $slugExistsLocally,
        ?string $explicitDecision = null,
    ): string {
        if ($hasLocalMap) {
            return self::WOULD_SKIP;
        }

        // Approved MAP_EXISTING (including companion maps where WP slug ≠ local slug,
        // e.g. course 8507 → microscope-course) always predicts map-only.
        if ($explicitDecision === 'MAP_EXISTING') {
            return self::WOULD_MAP_EXISTING;
        }

        if ($slugExistsLocally) {
            // Unmapped slug overlap — refuse create (same as persist MERGE_REQUIRES_DECISION).
            return self::WOULD_FAIL;
        }

        return self::WOULD_CREATE;
    }

    /**
     * Product shell classification.
     */
    public static function classifyProductShell(
        bool $hasLocalMap,
        bool $slugExistsLocally,
        bool $isVariable = false,
        ?string $explicitDecision = null,
    ): string {
        if ($isVariable) {
            return self::WOULD_SKIP;
        }

        if ($hasLocalMap) {
            return self::WOULD_SKIP;
        }

        if ($slugExistsLocally) {
            if ($explicitDecision === 'MAP_EXISTING') {
                return self::WOULD_MAP_EXISTING;
            }

            return self::WOULD_FAIL;
        }

        return self::WOULD_CREATE;
    }

    /**
     * Competition shell classification.
     *
     * @param  'MAP_EXISTING'|'CREATE_NEW'|null  $decision
     */
    public static function classifyCompetitionShell(
        bool $hasLocalMap,
        bool $localHundredPhotoExists,
        ?string $decision,
        bool $courseMapResolved,
    ): string {
        if ($hasLocalMap) {
            return self::WOULD_SKIP;
        }

        if ($decision === 'MAP_EXISTING') {
            // Target resolved at persist time (explicit local id or 100-photo candidate).
            return self::WOULD_MAP_EXISTING;
        }

        if ($decision === 'CREATE_NEW') {
            if ($localHundredPhotoExists) {
                return self::WOULD_FAIL;
            }
            if (! $courseMapResolved) {
                return self::WOULD_DEFER;
            }

            return self::WOULD_CREATE;
        }

        if ($localHundredPhotoExists) {
            return self::WOULD_FAIL;
        }

        return self::WOULD_DEFER;
    }

    /**
     * Enrollment / dependent row that needs upstream maps.
     */
    public static function classifyMappedDependent(
        bool $hasLocalMap,
        bool $upstreamMapsResolved,
    ): string {
        if ($hasLocalMap) {
            return self::WOULD_SKIP;
        }

        if (! $upstreamMapsResolved) {
            return self::WOULD_DEFER;
        }

        return self::WOULD_CREATE;
    }
}
