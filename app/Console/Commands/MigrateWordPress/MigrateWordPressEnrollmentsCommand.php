<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressEnrollmentImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;

final class MigrateWordPressEnrollmentsCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:enrollments
        {--dry-run : Report without modifying data}
        {--migration-run= : Active legacy_migration_runs.id required for real import}';

    protected $description = 'Import LearnDash enrollments. Real import requires WORDPRESS_REAL_PERSIST=1 and --migration-run.';

    public function handle(WordPressEnrollmentImporter $importer, WordPressRealPersistGate $gate): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $auth = $this->beginMutatingImport($gate, 'enrollment');
            if (! $auth['ok']) {
                return $auth['code'];
            }
        }

        try {
            $result = $importer->import($dryRun);
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
