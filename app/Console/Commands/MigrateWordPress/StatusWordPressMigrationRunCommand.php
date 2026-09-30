<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use Illuminate\Console\Command;
use RuntimeException;

final class StatusWordPressMigrationRunCommand extends Command
{
    protected $signature = 'migration:wordpress:run:status {run : legacy_migration_runs.id}';

    protected $description = 'Read-only status for a WordPress migration run (maps + ownership counts).';

    public function handle(MigrationRunService $runs): int
    {
        $id = (int) $this->argument('run');
        try {
            $report = $runs->statusReport($id);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
