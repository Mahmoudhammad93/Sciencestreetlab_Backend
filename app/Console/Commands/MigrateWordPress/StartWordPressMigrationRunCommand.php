<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class StartWordPressMigrationRunCommand extends Command
{
    protected $signature = 'migration:wordpress:run:start
        {--environment=staging : Environment label stored on the run}
        {--note= : Optional operator note}';

    protected $description = 'Start a WordPress migration run and print its id/uuid (does not import data).';

    public function handle(MigrationRunService $runs): int
    {
        $notes = [
            'started_via' => 'migration:wordpress:run:start',
            'app_env' => (string) config('app.env'),
        ];
        if ($this->option('note')) {
            $notes['note'] = (string) $this->option('note');
        }

        $sha = $this->safeGitSha();
        if ($sha !== null) {
            $notes['git_sha'] = $sha;
        }

        $run = $runs->start((string) $this->option('environment'), $notes);
        // Do not leave process-local binding from a start-only command.
        ActiveMigrationRun::clear();

        $payload = [
            'status' => 'started',
            'id' => $run->id,
            'uuid' => $run->uuid,
            'environment' => $run->environment,
            'run_status' => $run->status,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'notes' => $run->notes,
            'wrote_to_database' => true,
            'message' => 'Pass --migration-run='.$run->id.' to every mutating importer in this window.',
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('migration_run_id='.$run->id);

        return self::SUCCESS;
    }

    private function safeGitSha(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $process->setTimeout(5);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $sha = trim($process->getOutput());

            return preg_match('/^[0-9a-f]{7,40}$/i', $sha) === 1 ? $sha : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
