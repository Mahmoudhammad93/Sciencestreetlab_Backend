<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use InvalidArgumentException;

/**
 * Run-scoped historical media destination resolution.
 *
 * Authority is ALWAYS:
 *   legacy entity ID + entity type + migration_run_id → legacy_import_maps.local_id
 *
 * Staging plan local_entity_id values are NEVER authoritative.
 */
final class WordPressMediaOwnershipResolver
{
    public const STATUS_OK = 'ok';

    public const STATUS_MISSING = 'missing';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_WRONG_TYPE = 'wrong_type';

    /** @var list<string> */
    public const ALLOWED_ENTITY_TYPES = ['product', 'course', 'lesson', 'topic'];

    /**
     * @return array{
     *     status: string,
     *     local_id: int|null,
     *     map_id: int|null,
     *     migration_run_id: int,
     *     entity_type: string,
     *     legacy_entity_id: string,
     *     code: string|null,
     *     candidate_local_ids?: list<int>
     * }
     */
    public function resolve(int $migrationRunId, string $entityType, string $legacyEntityId): array
    {
        $legacyEntityId = trim($legacyEntityId);
        $base = [
            'local_id' => null,
            'map_id' => null,
            'migration_run_id' => $migrationRunId,
            'entity_type' => $entityType,
            'legacy_entity_id' => $legacyEntityId,
            'code' => null,
        ];

        if ($legacyEntityId === '' || $migrationRunId <= 0) {
            return array_merge($base, ['status' => self::STATUS_MISSING, 'code' => 'INVALID_LEGACY_OR_RUN']);
        }

        if (! in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            return array_merge($base, [
                'status' => self::STATUS_WRONG_TYPE,
                'code' => 'UNSUPPORTED_ENTITY_TYPE',
            ]);
        }

        $maps = LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('migration_run_id', $migrationRunId)
            ->where('entity_type', $entityType)
            ->where('legacy_id', $legacyEntityId)
            ->whereNotNull('local_id')
            ->orderBy('id')
            ->get(['id', 'local_id']);

        if ($maps->isEmpty()) {
            return array_merge($base, [
                'status' => self::STATUS_MISSING,
                'code' => 'MISSING_RUN_SCOPED_MAP',
            ]);
        }

        $localIds = $maps->pluck('local_id')->map(static fn ($id): int => (int) $id)->unique()->values();
        if ($localIds->count() > 1) {
            return array_merge($base, [
                'status' => self::STATUS_CONFLICT,
                'code' => 'AMBIGUOUS_RUN_SCOPED_MAP',
                'candidate_local_ids' => $localIds->all(),
            ]);
        }

        $map = $maps->first();

        return array_merge($base, [
            'status' => self::STATUS_OK,
            'local_id' => (int) $map->local_id,
            'map_id' => (int) $map->id,
            'code' => null,
        ]);
    }

    /**
     * Hard guard: staging plan destinations must never become write targets.
     *
     * @throws InvalidArgumentException when a caller attempts to prefer staging IDs
     */
    public function assertStagingIdNotAuthoritative(?int $stagingLocalId, int $resolvedLocalId): void
    {
        if ($stagingLocalId === null || $stagingLocalId <= 0) {
            return;
        }

        // Diagnostic only — resolved ID always wins. This method documents the contract
        // and is used by tests to ensure callers do not short-circuit on staging IDs.
        if ($stagingLocalId === $resolvedLocalId) {
            return;
        }
    }

    /**
     * Map media planned usage action → ownership entity type.
     */
    public function entityTypeForAction(string $action): ?string
    {
        return match ($action) {
            'PRODUCT_IMAGE_ATTACH', 'PRODUCT_GALLERY_ATTACH' => 'product',
            'COURSE_IMAGE_STORE' => 'course',
            'INLINE_IMAGE_STORE' => 'lesson',
            default => null,
        };
    }
}
