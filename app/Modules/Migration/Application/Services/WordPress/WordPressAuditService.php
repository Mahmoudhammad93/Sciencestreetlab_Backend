<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Models\User;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

final class WordPressAuditService
{
    public function __construct(
        private readonly LegacyImportMapRepository $maps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $entityTypes = ['user', 'course', 'enrollment', 'order', 'transaction'];
        $mapCounts = [];
        foreach ($entityTypes as $type) {
            $mapCounts[$type] = LegacyImportMap::query()
                ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
                ->where('entity_type', $type)
                ->count();
        }

        $localCounts = [
            'users' => User::query()->count(),
            'courses' => Course::query()->count(),
            'enrollments' => Enrollment::query()->count(),
            'orders' => Order::query()->count(),
        ];

        $duplicateEmails = LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('entity_type', 'user')
            ->whereNotNull('legacy_email')
            ->selectRaw('legacy_email, COUNT(*) as occurrences')
            ->groupBy('legacy_email')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'legacy_email' => $row->legacy_email,
                'occurrences' => (int) $row->occurrences,
            ])
            ->values()
            ->all();

        $orphanEnrollments = $this->detectOrphanEnrollments();

        return [
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'map_counts' => $mapCounts,
            'local_counts' => $localCounts,
            'duplicate_emails' => $duplicateEmails,
            'orphan_enrollments' => $orphanEnrollments,
            'orphan_enrollment_count' => count($orphanEnrollments),
            'blocked_reason' => WordPressConnectionService::BLOCKED,
        ];
    }

    /**
     * @return list<array{legacy_id: string, missing_user_map: bool, missing_course_map: bool, metadata: mixed}>
     */
    private function detectOrphanEnrollments(): array
    {
        /** @var Collection<int, LegacyImportMap> $enrollmentMaps */
        $enrollmentMaps = LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('entity_type', 'enrollment')
            ->get();

        $orphans = [];

        foreach ($enrollmentMaps as $map) {
            $meta = is_array($map->metadata) ? $map->metadata : [];
            $legacyUserId = isset($meta['legacy_user_id']) ? (string) $meta['legacy_user_id'] : null;
            $legacyCourseId = isset($meta['legacy_course_id']) ? (string) $meta['legacy_course_id'] : null;

            $missingUser = $legacyUserId !== null
                && $this->maps->find('user', $legacyUserId) === null;
            $missingCourse = $legacyCourseId !== null
                && $this->maps->find('course', $legacyCourseId) === null;

            // Also treat missing local_id as orphan when no resolved IDs in metadata.
            if ($legacyUserId === null && $legacyCourseId === null && $map->local_id === null) {
                $orphans[] = [
                    'legacy_id' => $map->legacy_id,
                    'missing_user_map' => true,
                    'missing_course_map' => true,
                    'metadata' => $meta,
                ];

                continue;
            }

            if ($missingUser || $missingCourse) {
                $orphans[] = [
                    'legacy_id' => $map->legacy_id,
                    'missing_user_map' => $missingUser,
                    'missing_course_map' => $missingCourse,
                    'metadata' => $meta,
                ];
            }
        }

        return $orphans;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function writeJson(string $path, array $report): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function writeCsv(string $path, array $report): void
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV path: {$path}");
        }

        fputcsv($handle, ['section', 'key', 'value']);

        foreach ($report['map_counts'] as $key => $value) {
            fputcsv($handle, ['map_counts', $key, $value]);
        }
        foreach ($report['local_counts'] as $key => $value) {
            fputcsv($handle, ['local_counts', $key, $value]);
        }
        fputcsv($handle, ['summary', 'duplicate_email_count', count($report['duplicate_emails'])]);
        fputcsv($handle, ['summary', 'orphan_enrollment_count', $report['orphan_enrollment_count']]);

        foreach ($report['duplicate_emails'] as $row) {
            fputcsv($handle, ['duplicate_email', $row['legacy_email'], $row['occurrences']]);
        }
        foreach ($report['orphan_enrollments'] as $row) {
            fputcsv($handle, [
                'orphan_enrollment',
                $row['legacy_id'],
                json_encode([
                    'missing_user_map' => $row['missing_user_map'],
                    'missing_course_map' => $row['missing_course_map'],
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        fclose($handle);
    }
}
