<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

/**
 * Course import from LearnDash / WP course CPTs.
 *
 * LearnDash table and meta mappings are BLOCKED_UNTIL_WORDPRESS_DB_DUMP.
 * Only optional core wp_posts presence may be probed — no invented column SQL.
 */
final class WordPressCourseImporter
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        // Probe posts only as a connectivity signal; LearnDash mapping stays blocked.
        $ready = $this->connection->assertReadyForImport('posts');

        return [
            'status' => 'blocked',
            'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
            'entity_type' => 'course',
            'dry_run' => $dryRun,
            'imported' => 0,
            'skipped' => 0,
            'created' => 0,
            'message' => 'LearnDash / course source SQL is BLOCKED_UNTIL_WORDPRESS_DB_DUMP. Do not invent sfwd-courses column mappings.',
            'inspect' => $ready['inspect'],
        ];
    }
}
