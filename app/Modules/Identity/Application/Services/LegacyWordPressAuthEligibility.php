<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Models\User;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;

/**
 * Strict Run-1 USER map eligibility for legacy WordPress first-login upgrade.
 *
 * Authority is ONLY legacy_import_maps — never email/ID-range/created_at.
 */
final class LegacyWordPressAuthEligibility
{
    public const STRATEGY_RESET_REQUIRED = 'reset_required';

    public const STRATEGY_UPGRADED = 'upgraded';

    public const STRATEGY_RESET = 'reset';

    public const STRATEGY_CHANGED = 'changed';

    public function __construct(
        private readonly int $migrationRunId = 1,
    ) {}

    public static function fromConfig(): self
    {
        return new self((int) config('wordpress.legacy_auth.migration_run_id', 1));
    }

    public function migrationRunId(): int
    {
        return $this->migrationRunId;
    }

    public function findEligibleMap(User $user, bool $forUpdate = false): ?LegacyImportMap
    {
        if ($user->id === null || $this->migrationRunId <= 0) {
            return null;
        }

        $query = LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('migration_run_id', $this->migrationRunId)
            ->where('entity_type', 'user')
            ->where('local_id', $user->id)
            ->orderBy('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $map = $query->first();

        if ($map === null) {
            return null;
        }

        $strategy = $this->passwordStrategy($map);
        if ($strategy !== self::STRATEGY_RESET_REQUIRED) {
            return null;
        }

        $legacyId = trim((string) $map->legacy_id);
        if ($legacyId === '') {
            return null;
        }

        return $map;
    }

    public function isEligible(User $user): bool
    {
        return $this->findEligibleMap($user) !== null;
    }

    public function passwordStrategy(LegacyImportMap $map): ?string
    {
        $meta = is_array($map->metadata) ? $map->metadata : [];
        $strategy = $meta['password_strategy'] ?? null;

        return is_string($strategy) ? $strategy : null;
    }

    /**
     * Revoke legacy fallback without deleting ownership map.
     */
    public function revoke(LegacyImportMap $map, string $strategy, ?string $timestampKey = null): void
    {
        if (! in_array($strategy, [self::STRATEGY_UPGRADED, self::STRATEGY_RESET, self::STRATEGY_CHANGED], true)) {
            $strategy = self::STRATEGY_CHANGED;
        }

        $meta = is_array($map->metadata) ? $map->metadata : [];
        $meta['password_strategy'] = $strategy;
        if ($timestampKey !== null) {
            $meta[$timestampKey] = now()->toIso8601String();
        }

        $map->metadata = $meta;
        $map->save();
    }

    /**
     * Revoke by destination user if a Run1 USER map still has reset_required.
     */
    public function revokeForUser(User $user, string $strategy, ?string $timestampKey = null): void
    {
        if ($user->id === null || $this->migrationRunId <= 0) {
            return;
        }

        $map = LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('migration_run_id', $this->migrationRunId)
            ->where('entity_type', 'user')
            ->where('local_id', $user->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($map === null) {
            return;
        }

        if ($this->passwordStrategy($map) !== self::STRATEGY_RESET_REQUIRED) {
            return;
        }

        $this->revoke($map, $strategy, $timestampKey);
    }
}
