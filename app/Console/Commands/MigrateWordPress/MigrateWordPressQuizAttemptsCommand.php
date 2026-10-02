<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizAttemptHistoryAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizAttemptImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class MigrateWordPressQuizAttemptsCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:quiz-attempts
        {--migration-run= : legacy_migration_runs.id (required for importer path)}
        {--analyze-only : Run read-only analyzer without requiring migration run}
        {--user-maps= : Optional TSV path legacy_user_id\\tlocal_user_id}
        {--course-maps= : Optional TSV path legacy_course_id\\tlocal_course_id}
        {--enrollments= : Optional TSV path enrollment_id\\tlocal_user_id\\tlocal_course_id}
        {--chunk-size=50 : Attempts per transaction chunk (execute only)}
        {--dry-run : Report without modifying data (default when --execute absent)}
        {--execute : Perform DB writes (requires WORDPRESS_REAL_PERSIST=1)}';

    protected $description = 'Analyze/import LearnDash historical quiz attempts. Default dry-run; never fires QuizPassed/emails.';

    public function handle(
        WordPressQuizAttemptHistoryAnalyzer $analyzer,
        WordPressQuizAttemptImporter $importer,
        WordPressRealPersistGate $gate,
        MigrationRunService $runs,
    ): int {
        $maps = $importer->hydrateMaps($this->loadMaps());
        $maps['chunk_size'] = max(1, (int) $this->option('chunk-size'));

        if ((bool) $this->option('analyze-only') || ! $this->option('migration-run')) {
            $result = $analyzer->analyze($maps);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ($result['status'] ?? '') === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $runId = $this->migrationRunOption();
        if ($runId === null || $runId <= 0) {
            $this->error(WordPressRealPersistGate::MIGRATION_RUN_REQUIRED);

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $forceDryRun = (bool) $this->option('dry-run');
        $dryRun = ! $execute || $forceDryRun;

        try {
            $runs->bindRunning($runId);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $dryRun) {
            $auth = $gate->authorizeMutation($runId, WordPressQuizAttemptImporter::ENTITY_ATTEMPT);
            if (! $auth['ok']) {
                $this->line(json_encode($auth['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }
            // Arm only after REAL_PERSIST gate passes — never set for dry-run.
            $maps['allow_real_attempt_persist'] = true;
        }

        try {
            $result = $importer->import($runId, $dryRun, $maps);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ($result['status'] ?? '') === 'ok' || ($result['status'] ?? '') === 'dry_run'
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->endMutatingImport();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMaps(): array
    {
        $maps = [
            'user_maps' => $this->readTsvMap((string) ($this->option('user-maps') ?: '')),
            'course_maps' => $this->readTsvMap((string) ($this->option('course-maps') ?: '')),
            'enrollments' => [],
            'approved_pending_users' => ['5' => 3],
            'source_orphan_users' => ['3'],
        ];

        $enrollPath = (string) ($this->option('enrollments') ?: '');
        if ($enrollPath !== '' && is_file($enrollPath)) {
            foreach (file($enrollPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $parts = explode("\t", $line);
                if (count($parts) < 3) {
                    continue;
                }
                [$eid, $uid, $cid] = $parts;
                $maps['enrollments'][$uid.'|'.$cid] = (int) $eid;
            }
        }

        return $maps;
    }

    /**
     * @return array<string, int>
     */
    private function readTsvMap(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 2) {
                continue;
            }
            $out[(string) $parts[0]] = (int) $parts[1];
        }

        return $out;
    }
}
