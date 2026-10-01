<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class MigrateWordPressQuizzesCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:quizzes
        {--migration-run= : legacy_migration_runs.id (required; must be running)}
        {--dry-run : Report without modifying data (default when --execute is absent)}
        {--execute : Perform DB writes (requires WORDPRESS_REAL_PERSIST=1)}';

    protected $description = 'Import LearnDash/Pro Quiz definitions for approved course-tree quizzes. Default dry-run; never imports attempts/media.';

    public function handle(
        WordPressQuizImporter $importer,
        WordPressRealPersistGate $gate,
        MigrationRunService $runs,
    ): int {
        $runId = $this->migrationRunOption();
        if ($runId === null || $runId <= 0) {
            $this->line(json_encode($gate->blocked(
                WordPressRealPersistGate::MIGRATION_RUN_REQUIRED,
                WordPressQuizImporter::ENTITY_QUIZ,
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

        try {
            // Bind running run for dry-run and real (map scoping / ActiveMigrationRun).
            $runs->bindRunning($runId);
        } catch (RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found')
                ? WordPressRealPersistGate::MIGRATION_RUN_INVALID
                : WordPressRealPersistGate::MIGRATION_RUN_NOT_ACTIVE;
            $this->line(json_encode($gate->blocked($code, WordPressQuizImporter::ENTITY_QUIZ, $e->getMessage()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error($code);

            return self::FAILURE;
        }

        if (! $dryRun) {
            $auth = $gate->authorizeMutation($runId, WordPressQuizImporter::ENTITY_QUIZ);
            if (! $auth['ok']) {
                $this->line(json_encode($auth['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->error((string) ($auth['result']['code'] ?? WordPressRealPersistGate::REAL_PERSIST_NOT_AUTHORIZED));
                $this->endMutatingImport();

                return self::FAILURE;
            }
        }

        try {
            $result = $importer->import($runId, $dryRun);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (($result['status'] ?? '') === 'blocked') {
                $this->error((string) ($result['code'] ?? 'blocked'));

                return self::FAILURE;
            }

            if ($dryRun) {
                $this->info('Dry-run only. Re-run with --execute and WORDPRESS_REAL_PERSIST=1 to mutate.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line(json_encode([
                'status' => 'failed',
                'entity_type' => WordPressQuizImporter::ENTITY_QUIZ,
                'migration_run_id' => $runId,
                'dry_run' => $dryRun,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->endMutatingImport();
        }
    }
}
