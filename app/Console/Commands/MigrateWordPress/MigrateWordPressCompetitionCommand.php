<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressCompetitionImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;

final class MigrateWordPressCompetitionCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:competition
        {--dry-run : Report without modifying data}
        {--migration-run= : Active legacy_migration_runs.id required for real import}
        {--decision= : Explicit competition identity decision: MAP_EXISTING or CREATE_NEW}
        {--existing-local-id= : Local competitions.id when --decision=MAP_EXISTING}';

    protected $description = 'Import AQ competition. Real import requires WORDPRESS_REAL_PERSIST=1, --migration-run, and --decision.';

    public function handle(WordPressCompetitionImporter $importer, WordPressRealPersistGate $gate): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $auth = $this->beginMutatingImport($gate, 'competition');
            if (! $auth['ok']) {
                return $auth['code'];
            }
        }

        try {
            $existing = $this->option('existing-local-id');
            $result = $importer->import(
                $dryRun,
                $this->option('decision') !== null && $this->option('decision') !== ''
                    ? (string) $this->option('decision')
                    : null,
                is_numeric($existing) ? (int) $existing : null,
            );
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (! $dryRun && ($result['status'] ?? '') === 'blocked') {
                $this->error((string) ($result['code'] ?? 'blocked'));

                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            if (! $dryRun) {
                $this->endMutatingImport();
            }
        }
    }
}
