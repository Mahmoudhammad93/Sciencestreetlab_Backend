<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressProductReviewImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;

final class MigrateWordPressReviewsCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:reviews
        {--dry-run : Report without modifying data}
        {--migration-run= : Active legacy_migration_runs.id required for real import}';

    protected $description = 'Import WooCommerce product reviews (historical). Guests use nullable user_id. Live API auth unchanged. Requires WORDPRESS_REAL_PERSIST=1 for real import.';

    public function handle(WordPressProductReviewImporter $importer, WordPressRealPersistGate $gate): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $auth = $this->beginMutatingImport($gate, 'product_review');
            if (! $auth['ok']) {
                return $auth['code'];
            }
        }

        try {
            $result = $importer->import($dryRun);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (($result['status'] ?? '') === 'blocked') {
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
