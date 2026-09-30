<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressOrderTimestampRepairService;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Repair historical WordPress order timestamps under an active migration run.
 *
 * DEFAULT is dry-run. Mutation requires --execute AND WORDPRESS_REAL_PERSIST=1
 * AND --migration-run=<running id>.
 */
final class RepairWordPressOrderTimestampsCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:repair-order-timestamps
        {--migration-run= : legacy_migration_runs.id (required; must be running)}
        {--execute : Apply timestamp repairs (default is dry-run)}
        {--dry-run : Force dry-run (default when --execute is absent)}';

    protected $description = 'Repair UTC timestamps on migration-created historical WP orders. Default dry-run; --execute requires WORDPRESS_REAL_PERSIST=1.';

    public function handle(
        WordPressOrderTimestampRepairService $repair,
        WordPressRealPersistGate $gate,
    ): int {
        $runId = $this->migrationRunOption();
        if ($runId === null || $runId <= 0) {
            $this->line(json_encode($gate->blocked(
                WordPressRealPersistGate::MIGRATION_RUN_REQUIRED,
                'order_timestamp_repair',
                'Pass --migration-run=<id> of a running WordPress migration run.',
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
            $auth = $gate->authorizeMutation($runId, 'order_timestamp_repair');
            if (! $auth['ok']) {
                $this->line(json_encode($auth['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->error((string) ($auth['result']['code'] ?? WordPressRealPersistGate::REAL_PERSIST_NOT_AUTHORIZED));

                return self::FAILURE;
            }
        }

        try {
            $result = $repair->repair($runId, $dryRun);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (($result['failed'] ?? 0) > 0) {
                $this->error('Timestamp repair reported failures.');

                return self::FAILURE;
            }

            if ($dryRun) {
                $this->info('Dry-run only. Re-run with --execute and WORDPRESS_REAL_PERSIST=1 to mutate.');
            } else {
                $this->warn('Timestamp repair executed for migration_run_id='.$runId);
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->line(json_encode([
                'status' => 'blocked',
                'code' => WordPressRealPersistGate::MIGRATION_RUN_NOT_ACTIVE,
                'entity_type' => 'order_timestamp_repair',
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $dryRun) {
                $this->endMutatingImport();
            }
        }
    }
}
