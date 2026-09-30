<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use RuntimeException;

/**
 * Central authorization for WordPress → Laravel mutating imports.
 *
 * Dry-run is always allowed.
 * Real mutation requires WORDPRESS_REAL_PERSIST=1 and an active migration run id.
 */
final class WordPressRealPersistGate
{
    public const REAL_PERSIST_NOT_AUTHORIZED = 'REAL_PERSIST_NOT_AUTHORIZED';

    public const MIGRATION_RUN_REQUIRED = 'MIGRATION_RUN_REQUIRED';

    public const MIGRATION_RUN_INVALID = 'MIGRATION_RUN_INVALID';

    public const MIGRATION_RUN_NOT_ACTIVE = 'MIGRATION_RUN_NOT_ACTIVE';

    public function __construct(
        private readonly MigrationRunService $runs,
    ) {}

    public function realPersistEnabled(): bool
    {
        return (string) config('wordpress.real_persist') === '1';
    }

    /**
     * Authorize a mutating (non-dry-run) WordPress import/map/rollback execute.
     *
     * @return array{ok: true, run: LegacyMigrationRun}|array{ok: false, result: array<string, mixed>}
     */
    public function authorizeMutation(?int $migrationRunId, string $entityType = 'wordpress'): array
    {
        if (! $this->realPersistEnabled()) {
            return [
                'ok' => false,
                'result' => $this->blocked(
                    self::REAL_PERSIST_NOT_AUTHORIZED,
                    $entityType,
                    'Real WordPress import is not authorized. Set WORDPRESS_REAL_PERSIST=1 only for an approved staging window, and pass --migration-run=<id>.',
                ),
            ];
        }

        if ($migrationRunId === null || $migrationRunId <= 0) {
            return [
                'ok' => false,
                'result' => $this->blocked(
                    self::MIGRATION_RUN_REQUIRED,
                    $entityType,
                    'Mutating WordPress import requires --migration-run=<id> of an active (running) migration run.',
                ),
            ];
        }

        try {
            $run = $this->runs->bindRunning($migrationRunId);
        } catch (RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found')
                ? self::MIGRATION_RUN_INVALID
                : self::MIGRATION_RUN_NOT_ACTIVE;

            return [
                'ok' => false,
                'result' => $this->blocked($code, $entityType, $e->getMessage()),
            ];
        }

        return ['ok' => true, 'run' => $run];
    }

    /**
     * Authorize rollback --execute (real persist + active/completed run that owns maps).
     *
     * @return array{ok: true, run: LegacyMigrationRun}|array{ok: false, result: array<string, mixed>}
     */
    public function authorizeRollbackExecute(int $migrationRunId): array
    {
        if (! $this->realPersistEnabled()) {
            return [
                'ok' => false,
                'result' => $this->blocked(
                    self::REAL_PERSIST_NOT_AUTHORIZED,
                    'rollback',
                    'Rollback --execute requires WORDPRESS_REAL_PERSIST=1.',
                ),
            ];
        }

        $run = LegacyMigrationRun::query()->find($migrationRunId);
        if ($run === null) {
            return [
                'ok' => false,
                'result' => $this->blocked(
                    self::MIGRATION_RUN_INVALID,
                    'rollback',
                    "Migration run {$migrationRunId} not found.",
                ),
            ];
        }

        if ($run->source !== LegacyImportMapRepository::SOURCE_WORDPRESS) {
            return [
                'ok' => false,
                'result' => $this->blocked(
                    self::MIGRATION_RUN_INVALID,
                    'rollback',
                    'Only wordpress migration runs can be rolled back.',
                ),
            ];
        }

        if ($run->status === 'rolled_back') {
            return [
                'ok' => false,
                'result' => $this->blocked(
                    self::MIGRATION_RUN_NOT_ACTIVE,
                    'rollback',
                    "Migration run {$migrationRunId} is already rolled_back.",
                ),
            ];
        }

        return ['ok' => true, 'run' => $run];
    }

    /**
     * Importer-level soft block (returns array, does not throw).
     *
     * @return array<string, mixed>|null null when authorized
     */
    public function importerBlockIfUnauthorized(bool $dryRun, string $entityType): ?array
    {
        if ($dryRun) {
            return null;
        }

        if (! $this->realPersistEnabled()) {
            return $this->blocked(
                self::REAL_PERSIST_NOT_AUTHORIZED,
                $entityType,
                'Real persist gated. Set WORDPRESS_REAL_PERSIST=1 and use --migration-run=<id> via Artisan.',
            );
        }

        if (ActiveMigrationRun::id() === null) {
            return $this->blocked(
                self::MIGRATION_RUN_REQUIRED,
                $entityType,
                'No active migration run bound. Pass --migration-run=<id> so maps are stamped for rollback.',
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function blocked(string $code, string $entityType, string $message): array
    {
        return [
            'status' => 'blocked',
            'code' => $code,
            'entity_type' => $entityType,
            'dry_run' => false,
            'wrote_to_database' => false,
            'imported' => 0,
            'created' => 0,
            'message' => $message,
        ];
    }
}
