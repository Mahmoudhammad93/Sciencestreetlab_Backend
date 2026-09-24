<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

/**
 * Enrollment import from LearnDash user–course relationships.
 *
 * Source-specific SQL is BLOCKED_UNTIL_WORDPRESS_DB_DUMP.
 * Idempotency will use legacy_import_maps once mappings exist.
 */
final class WordPressEnrollmentImporter
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
        $ready = $this->connection->assertReadyForImport('users');

        return [
            'status' => 'blocked',
            'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
            'entity_type' => 'enrollment',
            'dry_run' => $dryRun,
            'imported' => 0,
            'skipped' => 0,
            'created' => 0,
            'message' => 'LearnDash enrollment source SQL is BLOCKED_UNTIL_WORDPRESS_DB_DUMP. Requires resolved user + course legacy_import_maps.',
            'inspect' => $ready['inspect'],
            'note' => 'Orphan enrollments (missing user/course maps) will be reported by migration:wordpress:audit.',
        ];
    }
}
