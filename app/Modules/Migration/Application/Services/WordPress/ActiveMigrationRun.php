<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;

/**
 * Process-local pointer to the active WordPress migration run.
 * Set by MigrationRunService::start(); cleared on complete/rollback.
 * LegacyImportMapRepository stamps migration_run_id from this when omitted.
 */
final class ActiveMigrationRun
{
    private static ?int $runId = null;

    public static function set(?LegacyMigrationRun $run): void
    {
        self::$runId = $run?->id;
    }

    public static function id(): ?int
    {
        return self::$runId;
    }

    public static function clear(): void
    {
        self::$runId = null;
    }
}
