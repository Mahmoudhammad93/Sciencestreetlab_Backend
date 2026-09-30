<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressApprovedCollisionMapper;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;

/**
 * Apply (or dry-run) human-approved MAP_EXISTING collision maps.
 *
 * Default is dry-run. Real map writes require --execute + WORDPRESS_REAL_PERSIST=1
 * + --migration-run=<id>. Never mutates product/course/competition commercial rows.
 */
final class ApplyApprovedWordPressCollisionsCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:apply-approved-collisions
        {--dry-run : Simulate map-only outcomes without writing (default when --execute is absent)}
        {--execute : Upsert approved legacy_import_maps with mapped_to_existing ownership}
        {--migration-run= : Active legacy_migration_runs.id required with --execute}';

    protected $description = 'Dry-run or apply approved MAP_EXISTING collision maps. --execute requires WORDPRESS_REAL_PERSIST=1 and --migration-run.';

    public function handle(
        WordPressApprovedCollisionMapper $mapper,
        WordPressRealPersistGate $gate,
    ): int {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->warn('Both --execute and --dry-run were passed; dry-run wins (no writes).');
            $dryRun = true;
        }

        if (! $dryRun) {
            $auth = $this->beginMutatingImport($gate, 'approved_collision_map');
            if (! $auth['ok']) {
                return $auth['code'];
            }
        }

        try {
            $result = $dryRun ? $mapper->simulate() : $mapper->apply();
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if ($dryRun) {
                $this->info('Dry-run only: no legacy_import_maps written. Re-run with --execute --migration-run=<id> and WORDPRESS_REAL_PERSIST=1.');
            } else {
                $this->info('Applied approved MAP_EXISTING maps only (mapped_to_existing=true). Catalog rows untouched.');
            }

            return self::SUCCESS;
        } finally {
            if (! $dryRun) {
                $this->endMutatingImport();
            }
        }
    }
}
