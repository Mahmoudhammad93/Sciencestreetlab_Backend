<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressMediaRollbackService;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Media-scoped rollback for a WordPress MEDIA_RUN_ID.
 * DEFAULT dry-run. Never deletes Product/Course/Lesson/Topic rows.
 */
final class RollbackWordPressMediaCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:media-rollback
        {migration_run : legacy_migration_runs.id for the media M2 run}
        {--execute : Apply rollback (default is dry-run)}
        {--dry-run : Force dry-run}';

    protected $description = 'Plan/execute WordPress media M2 rollback. Default dry-run; does not delete Product/Course/Lesson/Topic rows.';

    public function handle(WordPressMediaRollbackService $rollback, WordPressRealPersistGate $gate): int
    {
        $runId = (int) $this->argument('migration_run');
        if ($runId <= 0) {
            $this->error('Invalid migration_run id');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $forceDryRun = (bool) $this->option('dry-run');
        $dryRun = ! $execute || $forceDryRun;

        if ($execute && $forceDryRun) {
            $this->warn('Both --execute and --dry-run were passed; dry-run wins.');
            $dryRun = true;
        }

        try {
            $result = $rollback->planOrExecute($runId, $dryRun);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (($result['would_delete_products'] ?? 0) !== 0
                || ($result['would_delete_courses'] ?? 0) !== 0
                || ($result['would_delete_lessons'] ?? 0) !== 0
                || ($result['would_delete_topics'] ?? 0) !== 0) {
                $this->error('Rollback plan incorrectly targets business entity deletes.');

                return self::FAILURE;
            }

            if ($dryRun) {
                $this->info('Rollback dry-run only. Pass --execute with WORDPRESS_REAL_PERSIST=1 to apply.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
