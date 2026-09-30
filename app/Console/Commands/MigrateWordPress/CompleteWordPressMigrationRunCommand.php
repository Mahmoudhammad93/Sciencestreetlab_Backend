<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Console\Command;
use RuntimeException;

final class CompleteWordPressMigrationRunCommand extends Command
{
    protected $signature = 'migration:wordpress:run:complete {run : legacy_migration_runs.id}';

    protected $description = 'Mark a running WordPress migration run as completed (no entity deletes).';

    public function handle(MigrationRunService $runs): int
    {
        $id = (int) $this->argument('run');
        $run = LegacyMigrationRun::query()->find($id);
        if ($run === null) {
            $this->error("Migration run {$id} not found.");

            return self::FAILURE;
        }
        if ($run->source !== LegacyImportMapRepository::SOURCE_WORDPRESS) {
            $this->error('Only wordpress migration runs can be completed by this command.');

            return self::FAILURE;
        }

        try {
            $completed = $runs->complete($run);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'status' => 'completed',
            'id' => $completed->id,
            'uuid' => $completed->uuid,
            'run_status' => $completed->status,
            'completed_at' => optional($completed->completed_at)?->toIso8601String(),
            'wrote_to_database' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
