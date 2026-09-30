<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;

final class MigrateWordPressProductsCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:products
        {--dry-run : Report without modifying data}
        {--migration-run= : Active legacy_migration_runs.id required for real import}';

    protected $description = 'Import WooCommerce products. Real import requires WORDPRESS_REAL_PERSIST=1, WORDPRESS_PRODUCT_SKU_STRATEGY=wp_id, and --migration-run.';

    public function handle(WordPressProductImporter $importer, WordPressRealPersistGate $gate): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun) {
            $auth = $this->beginMutatingImport($gate, 'product');
            if (! $auth['ok']) {
                return $auth['code'];
            }
        }

        try {
            $result = $importer->import($dryRun);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (($result['code'] ?? null) === WordPressProductImporter::SKU_REQUIRES_DECISION) {
                $this->warn(WordPressProductImporter::SKU_REQUIRES_DECISION);
            }
            if (($result['variable_note'] ?? null) !== null) {
                $this->warn((string) $result['variable_note']);
            }
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
