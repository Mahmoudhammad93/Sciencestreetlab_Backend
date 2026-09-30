<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LearnDash enrollment importer.
 *
 * Confirmed production enrollment signal:
 *   wp_usermeta.meta_key REGEXP '^course_[0-9]+_access_from$'
 *
 * Supporting signals (documented, not primary — never used alone to invent enrollment):
 *   learndash_course_{id}_enrolled_at
 *   learndash_user_activity.activity_type='access'
 *
 * Dry-run writes NOTHING.
 * Real persist creates Enrollment rows directly (no EnrollUserService).
 *
 * Side-effect safety: Enrollment::create only runs the verification-token boot
 * callback. It does not fire order/payment/Bosta/WhatsApp/email listeners.
 * EnrollUserService is intentionally avoided (plan entitlements / commerce paths).
 */
final class WordPressEnrollmentImporter
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        $ready = $this->connection->assertReadyForImport('users');
        if (! $ready['ok']) {
            return [
                'status' => 'blocked',
                'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
                'entity_type' => 'enrollment',
                'dry_run' => $dryRun,
                'imported' => 0,
                'created' => 0,
                'wrote_to_database' => false,
                'message' => 'Enrollment source not ready.',
                'inspect' => $ready['inspect'],
            ];
        }

        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();
        $meta = $this->connection->table('usermeta');
        $activity = $this->connection->table('learndash_user_activity');

        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'enrollment')) {
            $block['inspect'] = $ready['inspect'];

            return $block;
        }

        $accessFrom = (int) (DB::connection($conn)->table($meta)
            ->whereRaw('meta_key REGEXP ?', ['^course_[0-9]+_access_from$'])
            ->count());

        $enrolledAt = (int) (DB::connection($conn)->table($meta)
            ->whereRaw('meta_key REGEXP ?', ['^learndash_course_[0-9]+_enrolled_at$'])
            ->count());

        $accessActivity = (int) (DB::connection($conn)->table($activity)
            ->where('activity_type', 'access')
            ->count());

        $scanned = 0;
        $wouldCreate = 0;
        $wouldSkipMapped = 0;
        $missingUserMap = 0;
        $missingCourseMap = 0;
        $created = 0;
        $skippedMapped = 0;
        $skippedExisting = 0;
        $skippedUnresolved = 0;
        $samples = [];
        $reconciliation = [];

        DB::connection($conn)
            ->table($meta)
            ->whereRaw('meta_key REGEXP ?', ['^course_[0-9]+_access_from$'])
            ->orderBy('umeta_id')
            ->select(['umeta_id', 'user_id', 'meta_key', 'meta_value'])
            ->chunkById(200, function ($rows) use (
                $dryRun,
                &$scanned,
                &$wouldCreate,
                &$wouldSkipMapped,
                &$missingUserMap,
                &$missingCourseMap,
                &$created,
                &$skippedMapped,
                &$skippedExisting,
                &$skippedUnresolved,
                &$samples,
                &$reconciliation,
            ): void {
                foreach ($rows as $row) {
                    $scanned++;
                    if (! preg_match('/^course_(\d+)_access_from$/', (string) $row->meta_key, $m)) {
                        continue;
                    }
                    $courseLegacyId = $m[1];
                    $userLegacyId = (string) $row->user_id;
                    $legacyKey = $userLegacyId.':'.$courseLegacyId;
                    $userMap = $this->maps->find('user', $userLegacyId);
                    $courseMap = $this->maps->find('course', $courseLegacyId);
                    $enrollmentMap = $this->maps->find('enrollment', $legacyKey);

                    $unresolvedCodes = [];
                    if ($userMap?->local_id === null) {
                        $missingUserMap++;
                        $unresolvedCodes[] = 'UNRESOLVED_USER_MAP';
                    }
                    if ($courseMap?->local_id === null) {
                        $missingCourseMap++;
                        $unresolvedCodes[] = 'UNRESOLVED_COURSE_MAP';
                    }

                    if ($enrollmentMap?->local_id) {
                        $wouldSkipMapped++;
                        if (! $dryRun) {
                            $skippedMapped++;
                        }

                        continue;
                    }

                    if ($unresolvedCodes !== []) {
                        if (count($reconciliation) < 50) {
                            $reconciliation[] = [
                                'legacy_key' => $legacyKey,
                                'umeta_id' => (int) $row->umeta_id,
                                'user_legacy_id' => $userLegacyId,
                                'course_legacy_id' => $courseLegacyId,
                                'access_from' => $row->meta_value,
                                'codes' => $unresolvedCodes,
                                'action' => 'skipped_safe',
                            ];
                        }
                        if (! $dryRun) {
                            $skippedUnresolved++;
                        }

                        continue;
                    }

                    $wouldCreate++;

                    if ($dryRun && count($samples) < 5) {
                        $samples[] = [
                            'user_legacy_id' => $userLegacyId,
                            'course_legacy_id' => $courseLegacyId,
                            'access_from' => $row->meta_value,
                            'user_map' => $userMap?->local_id,
                            'course_map' => $courseMap?->local_id,
                            'legacy_key' => $legacyKey,
                        ];
                    }

                    if (! $dryRun) {
                        $result = $this->persistEnrollment(
                            $legacyKey,
                            (int) $userMap->local_id,
                            (int) $courseMap->local_id,
                            $userLegacyId,
                            $courseLegacyId,
                            (string) $row->meta_value,
                            (int) $row->umeta_id,
                        );
                        if ($result === 'created') {
                            $created++;
                        } elseif ($result === 'skipped_mapped') {
                            $skippedMapped++;
                        } else {
                            $skippedExisting++;
                        }
                    }
                }
            }, 'umeta_id');

        $mapsReady = $missingUserMap === 0 && $missingCourseMap === 0;
        $blocked = ! $dryRun && $created === 0 && $wouldCreate === 0 && ($missingUserMap > 0 || $missingCourseMap > 0);

        return [
            'status' => $dryRun ? 'ok' : ($blocked ? 'blocked' : 'ok'),
            'code' => $dryRun ? null : (
                $blocked ? 'ENROLLMENT_REQUIRES_USER_AND_COURSE_MAPS' : null
            ),
            'entity_type' => 'enrollment',
            'dry_run' => $dryRun,
            'enrollment_source' => 'wp_usermeta.meta_key = course_{ID}_access_from',
            'expected_source_rows' => 1194,
            'supporting_sources' => [
                'learndash_course_{ID}_enrolled_at' => $enrolledAt,
                "learndash_user_activity.activity_type='access'" => $accessActivity,
                'policy' => 'Supporting signals never invent enrollment alone',
            ],
            'access_from_rows' => $accessFrom,
            'scanned' => $scanned,
            'would_create_when_maps_exist' => $wouldCreate,
            'would_skip_mapped' => $wouldSkipMapped,
            'missing_user_map' => $missingUserMap,
            'missing_course_map' => $missingCourseMap,
            'created' => $dryRun ? 0 : $created,
            'skipped_mapped' => $dryRun ? 0 : $skippedMapped,
            'skipped_existing_pair' => $dryRun ? 0 : $skippedExisting,
            'skipped_unresolved' => $dryRun ? 0 : $skippedUnresolved,
            'imported' => $dryRun ? 0 : $created,
            'samples' => $samples,
            'reconciliation' => [
                'unresolved_sample' => $reconciliation,
                'codes' => [
                    'UNRESOLVED_USER_MAP' => 'User legacy_import_map missing or local_id null — never fabricate users',
                    'UNRESOLVED_COURSE_MAP' => 'Course legacy_import_map missing or local_id null — never fabricate courses',
                ],
                'unresolved_user_map_count' => $missingUserMap,
                'unresolved_course_map_count' => $missingCourseMap,
            ],
            'maps_ready' => $mapsReady,
            'wrote_to_database' => ! $dryRun && $created > 0,
            'side_effects' => [
                'enroll_user_service' => false,
                'access_events' => false,
                'email' => false,
                'whatsapp' => false,
                'payment' => false,
                'orders' => false,
                'bosta' => false,
                'plan_entitlements' => false,
                'mechanism' => 'Enrollment::query()->create (verification token boot only; no EnrollUserService)',
            ],
            'message' => $dryRun
                ? 'Dry-run enrollment inventory. Primary proof: course_{id}_access_from usermeta.'
                : ($blocked
                    ? 'Real enrollment import blocked until user+course legacy_import_maps exist.'
                    : 'Enrollment persist completed from course_{id}_access_from (direct rows; no access events).'),
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * Direct enrollment persist helper (no EnrollUserService / no commerce listeners).
     *
     * @return 'created'|'skipped_mapped'|'skipped_existing'
     */
    public function persistEnrollment(
        string $legacyKey,
        int $userLocalId,
        int $courseLocalId,
        string $userLegacyId,
        string $courseLegacyId,
        string $accessFromUnix,
        ?int $umetaId = null,
    ): string {
        if ($this->maps->find('enrollment', $legacyKey)?->local_id) {
            return 'skipped_mapped';
        }

        $existing = Enrollment::query()
            ->where('user_id', $userLocalId)
            ->where('course_id', $courseLocalId)
            ->first();

        if ($existing !== null) {
            $this->maps->upsertMapping('enrollment', $legacyKey, [
                'local_id' => $existing->id,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                    'user_legacy_id' => $userLegacyId,
                    'course_legacy_id' => $courseLegacyId,
                    'umeta_id' => $umetaId,
                    'collision' => 'EXISTING_USER_COURSE_PAIR',
                    'access_from' => $accessFromUnix,
                    'source' => 'course_{id}_access_from',
                ]),
            ]);

            return 'skipped_existing';
        }

        $enrolledAt = $this->parseAccessFrom($accessFromUnix);

        // Direct Enrollment row — do NOT call EnrollUserService (would create orders /
        // plan entitlement snapshots). course_plan_id stays null so access uses
        // sequential/non-plan gating in CourseAccessService.
        // Enrollment::booted creating only assigns enrollment_verification_token.
        $enrollment = Enrollment::query()->create([
            'user_id' => $userLocalId,
            'course_id' => $courseLocalId,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => $enrolledAt,
            'started_at' => $enrolledAt,
            'grant_certificate' => false,
        ]);

        $this->maps->upsertMapping('enrollment', $legacyKey, [
            'local_id' => $enrollment->id,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'user_legacy_id' => $userLegacyId,
                'course_legacy_id' => $courseLegacyId,
                'umeta_id' => $umetaId,
                'access_from' => $accessFromUnix,
                'source' => 'course_{id}_access_from',
                'side_effects' => [
                    'enroll_user_service' => false,
                    'plan_entitlements' => false,
                    'access_events' => false,
                    'email' => false,
                    'whatsapp' => false,
                    'payment' => false,
                    'orders' => false,
                    'bosta' => false,
                ],
            ]),
        ]);

        return 'created';
    }

    private function parseAccessFrom(string $raw): Carbon
    {
        if (ctype_digit($raw)) {
            return Carbon::createFromTimestamp((int) $raw);
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return now();
        }
    }
}
