<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Support\Carbon;

final class LegacyImportMapRepository
{
    public const SOURCE_WORDPRESS = 'wordpress';

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
     *     imported_at?: Carbon|string|null
     * }  $data
     */
    public function upsertMapping(
        string $entityType,
        string $legacyId,
        array $data = [],
        string $source = self::SOURCE_WORDPRESS,
    ): LegacyImportMap {
        $map = $this->findOrCreate($entityType, $legacyId, [], $source);

        $payload = array_filter([
            'local_id' => $data['local_id'] ?? null,
            'legacy_email' => $data['legacy_email'] ?? null,
            'checksum' => $data['checksum'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'imported_at' => $data['imported_at'] ?? null,
        ], static fn ($value) => $value !== null);

        if ($payload !== []) {
            $map->fill($payload);
            $map->save();
        }

        return $map->fresh() ?? $map;
    }
}
