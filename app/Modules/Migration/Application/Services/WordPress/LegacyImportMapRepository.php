<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Support\Carbon;

final class LegacyImportMapRepository
{
    public const SOURCE_WORDPRESS = 'wordpress';

    /** Metadata: local row was created by this migration and is rollback-deletable. */
    public const META_CREATED_BY_MIGRATION = 'created_by_migration';

    /** Metadata: map points at a pre-existing Laravel row — rollback must never delete it. */
    public const META_MAPPED_TO_EXISTING = 'mapped_to_existing';

    /**
     * Stamp ownership for a newly created Laravel entity.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function ownershipCreated(array $metadata = []): array
    {
        return array_merge($metadata, [
            self::META_CREATED_BY_MIGRATION => true,
            self::META_MAPPED_TO_EXISTING => false,
        ]);
    }

    /**
     * Stamp ownership for a map that only links to a pre-existing Laravel entity.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function ownershipMappedExisting(array $metadata = []): array
    {
        return array_merge($metadata, [
            self::META_CREATED_BY_MIGRATION => false,
            self::META_MAPPED_TO_EXISTING => true,
        ]);
    }

    public function find(
        string $entityType,
        string $legacyId,
        string $source = self::SOURCE_WORDPRESS,
    ): ?LegacyImportMap {
        return LegacyImportMap::query()
            ->where('source', $source)
            ->where('entity_type', $entityType)
            ->where('legacy_id', $legacyId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreate(
        string $entityType,
        string $legacyId,
        array $attributes = [],
        string $source = self::SOURCE_WORDPRESS,
    ): LegacyImportMap {
        $existing = $this->find($entityType, $legacyId, $source);
        if ($existing !== null) {
            return $existing;
        }

        return LegacyImportMap::query()->create(array_merge([
            'source' => $source,
            'entity_type' => $entityType,
            'legacy_id' => $legacyId,
        ], $attributes));
    }

    /**
     * Idempotent upsert keyed by (source, entity_type, legacy_id).
     *
     * @param  array{
     *     local_id?: int|null,
     *     legacy_email?: string|null,
     *     checksum?: string|null,
     *     metadata?: array<string, mixed>|null,
     *     imported_at?: Carbon|string|null,
     *     migration_run_id?: int|null
     * }  $data
     */
    public function upsertMapping(
        string $entityType,
        string $legacyId,
        array $data = [],
        string $source = self::SOURCE_WORDPRESS,
    ): LegacyImportMap {
        $map = $this->findOrCreate($entityType, $legacyId, [], $source);

        $migrationRunId = $data['migration_run_id'] ?? ActiveMigrationRun::id();
        if ($migrationRunId !== null && ! LegacyMigrationRun::query()->whereKey($migrationRunId)->exists()) {
            $migrationRunId = null;
            if (($data['migration_run_id'] ?? null) === null) {
                ActiveMigrationRun::clear();
            }
        }

        $payload = [];
        if (array_key_exists('local_id', $data)) {
            $payload['local_id'] = $data['local_id'];
        }
        foreach (['legacy_email', 'checksum', 'metadata', 'imported_at'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $payload[$key] = $data[$key];
            }
        }

        // Ownership immutability: once mapped_to_existing with a real local row,
        // never allow a re-run to flip to created_by_migration (would make rollback
        // delete a pre-existing Product/Course/Competition).
        // Exception: placeholder maps with local_id=null (e.g. former slot-schema
        // metadata-only submission maps) may upgrade to created_by_migration when
        // the business row is finally created.
        if (
            array_key_exists('metadata', $payload)
            && is_array($payload['metadata'])
            && $map->wasMappedToExisting()
            && $map->local_id !== null
        ) {
            $payload['metadata'] = self::ownershipMappedExisting($payload['metadata']);
        }

        if ($migrationRunId !== null) {
            $payload['migration_run_id'] = $migrationRunId;
        }

        if ($payload !== []) {
            $map->fill($payload);
            $map->save();
        }

        return $map->fresh() ?? $map;
    }
}
