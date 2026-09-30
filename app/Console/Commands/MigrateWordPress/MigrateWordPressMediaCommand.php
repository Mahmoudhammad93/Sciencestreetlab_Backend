<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressMediaImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * WordPress media M2 transfer/import.
 * DEFAULT dry-run. --execute requires WORDPRESS_REAL_PERSIST=1 and running --migration-run.
 */
final class MigrateWordPressMediaCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:media
        {--migration-run= : legacy_migration_runs.id (required; must be running)}
        {--execute : Perform downloads and DB writes (default is dry-run)}
        {--dry-run : Force dry-run (default when --execute is absent)}';

    protected $description = 'Transfer/import WordPress media (148-file M2 scope). Default dry-run; --execute requires WORDPRESS_REAL_PERSIST=1.';

    public function handle(WordPressMediaImporter $importer, WordPressRealPersistGate $gate): int
    {
        $runId = $this->migrationRunOption();
        if ($runId === null || $runId <= 0) {
            $this->line(json_encode($gate->blocked(
                WordPressRealPersistGate::MIGRATION_RUN_REQUIRED,
                'media',
                'Pass --migration-run=<id> of a running WordPress media migration run.',
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error(WordPressRealPersistGate::MIGRATION_RUN_REQUIRED);

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $forceDryRun = (bool) $this->option('dry-run');
        $dryRun = ! $execute || $forceDryRun;

        if ($execute && $forceDryRun) {
            $this->warn('Both --execute and --dry-run were passed; dry-run wins (no writes).');
            $dryRun = true;
        }

        if (! $dryRun) {
            $auth = $gate->authorizeMutation($runId, 'media');
            if (! $auth['ok']) {
                $this->line(json_encode($auth['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->error((string) ($auth['result']['code'] ?? WordPressRealPersistGate::REAL_PERSIST_NOT_AUTHORIZED));

                return self::FAILURE;
            }
        }

        try {
            $result = $importer->import($runId, $dryRun);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (($result['files_failed'] ?? 0) > 0 || ($result['status'] ?? '') === 'blocked') {
                $this->error('Media import reported failures or was blocked.');

                return self::FAILURE;
            }

            if (($result['conflicts'] ?? []) !== []) {
                $this->warn('Conflicts present — review report.');
            }

            if ($dryRun) {
                $this->info('Dry-run only. Re-run with --execute and WORDPRESS_REAL_PERSIST=1 to mutate.');
            } else {
                $this->warn('Media import executed for migration_run_id='.$runId);
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->line(json_encode([
                'status' => 'failed',
                'entity_type' => 'media',
                'migration_run_id' => $runId,
                'dry_run' => $dryRun,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->line(json_encode([
                'status' => 'failed',
                'entity_type' => 'media',
                'migration_run_id' => $runId,
                'dry_run' => $dryRun,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $dryRun) {
                $this->endMutatingImport();
            }
        }
    }
}
