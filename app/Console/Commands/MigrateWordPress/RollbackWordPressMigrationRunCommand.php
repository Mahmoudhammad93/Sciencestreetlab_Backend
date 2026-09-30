<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Console\Command;

/**
 * Rollback a WordPress migration run.
 *
 * DEFAULT is dry-run (no deletes). Real rollback requires --execute AND WORDPRESS_REAL_PERSIST=1.
 */
final class RollbackWordPressMigrationRunCommand extends Command
{
    protected $signature = 'migration:wordpress:rollback
        {run : legacy_migration_runs.id}
        {--execute : Actually delete migration-owned rows / unmap MAP_EXISTING maps for this run}
        {--dry-run : Force dry-run (default when --execute is absent)}';

    protected $description = 'Rollback WordPress migration run. Default dry-run. --execute requires WORDPRESS_REAL_PERSIST=1.';

    public function handle(MigrationRunService $runs, WordPressRealPersistGate $gate): int
    {
        $id = (int) $this->argument('run');
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->warn('Both --execute and --dry-run were passed; dry-run wins (no writes).');
            $dryRun = true;
        }

        if ($dryRun) {
            $run = LegacyMigrationRun::query()->find($id);
            if ($run === null || $run->source !== LegacyImportMapRepository::SOURCE_WORDPRESS) {
                $this->error("WordPress migration run {$id} not found.");

                return self::FAILURE;
            }
            $plan = $runs->rollback($run, true);
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Dry-run only. Re-run with --execute and WORDPRESS_REAL_PERSIST=1 to mutate.');

            return self::SUCCESS;
        }

        $auth = $gate->authorizeRollbackExecute($id);
        if (! $auth['ok']) {
            $this->line(json_encode($auth['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error((string) ($auth['result']['code'] ?? WordPressRealPersistGate::REAL_PERSIST_NOT_AUTHORIZED));

            return self::FAILURE;
        }

        $result = $runs->rollback($auth['run'], false);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->warn('Rollback executed for migration_run_id='.$id);

        return self::SUCCESS;
    }
}
