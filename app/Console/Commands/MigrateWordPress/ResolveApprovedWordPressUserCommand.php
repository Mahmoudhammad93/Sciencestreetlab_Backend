<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressApprovedUserResolutionService;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;

/**
 * Explicit approved legacy-user resolution (e.g. U5-A restore + MAP_EXISTING).
 *
 * Dry-run never writes. Real mode requires WORDPRESS_REAL_PERSIST=1 and --migration-run.
 */
final class ResolveApprovedWordPressUserCommand extends Command
{
    use InteractsWithWordPressMigrationAuthorization;

    protected $signature = 'migration:wordpress:resolve-approved-user
        {legacy_id : WordPress user ID (must match an approved_user_resolutions entry)}
        {--dry-run : Report without modifying data}
        {--migration-run= : Active legacy_migration_runs.id required for real execution}';

    protected $description = 'Apply a narrowly approved user resolution (e.g. restore+map WP5→Laravel3). Never copies WP passwords. Dry-run by default recommended.';

    public function handle(
        WordPressApprovedUserResolutionService $resolver,
        WordPressRealPersistGate $gate,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $legacyId = (string) $this->argument('legacy_id');

        if (! $dryRun) {
            $auth = $this->beginMutatingImport($gate, 'approved_user_resolution');
            if (! $auth['ok']) {
                return $auth['code'];
            }
        }

        try {
            $result = $resolver->resolve($legacyId, $dryRun);
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
