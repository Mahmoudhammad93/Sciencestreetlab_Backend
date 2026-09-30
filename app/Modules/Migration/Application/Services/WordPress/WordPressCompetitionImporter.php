<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Application\Services\ParticipantProgressService;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AQ (production 100-image challenge) competition importer.
 * LPC is intentionally ignored.
 *
 * Dry-run writes NOTHING.
 *
 * Competition identity decision is NEVER auto-chosen:
 *   MAP_EXISTING — create legacy_import_map → existing local competition (no overwrite)
 *   CREATE_NEW   — create Competition via migration-safe persistence
 *
 * Submissions: sample_number / photo_index mapping remains AMBIGUOUS from sample_name.
 * Schema allows NULL slots for historical rows (COMPETITION_SLOT_SCHEMA_RESOLVED).
 * This importer never invents slot values. Media stays MEDIA_PENDING (no Spatie attach).
 */
final class WordPressCompetitionImporter
{
    public const DECISION_MAP_EXISTING = 'MAP_EXISTING';

    public const DECISION_CREATE_NEW = 'CREATE_NEW';

    public const SLOT_SCHEMA_BLOCKER = 'COMPETITION_SLOT_SCHEMA_BLOCKER';

    public const SLOT_SCHEMA_RESOLVED = 'COMPETITION_SLOT_SCHEMA_RESOLVED';

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressApprovedCollisionMapper $approvedCollisions,
        private readonly ParticipantProgressService $progress,
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(
        bool $dryRun = false,
        ?string $competitionDecision = null,
        ?int $existingLocalId = null,
    ): array {
        $ready = $this->connection->assertReadyForImport('aq_competitions');
        if (! $ready['ok']) {
            return [
                'status' => 'blocked',
                'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
                'entity_type' => 'competition',
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'inspect' => $ready['inspect'],
            ];
        }

        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();

        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'competition')) {
            $block['inspect'] = $ready['inspect'];

            return $block;
        }

        $decision = $this->normalizeDecision(
            $competitionDecision ?? config('wordpress.competition_decision'),
        );
        // Approved registry informs dry-run prediction only; real persist still requires
        // explicit --decision / WORDPRESS_COMPETITION_DECISION (never silent auto-choice).
        $approvedDecision = $this->approvedCollisions->competitionDecision('4');
        $predictedDecision = $decision ?? $approvedDecision;

        $existingLocalId = $existingLocalId
            ?? (config('wordpress.competition_existing_local_id') !== null
                ? (int) config('wordpress.competition_existing_local_id')
                : null);
        if ($existingLocalId !== null && $existingLocalId <= 0) {
            $existingLocalId = null;
        }
        if ($existingLocalId === null && ($decision === self::DECISION_MAP_EXISTING || $predictedDecision === self::DECISION_MAP_EXISTING)) {
            $approvedSlug = $this->approvedCollisions->approvedCompetitionLocalSlug('4');
            if ($approvedSlug !== null) {
                $existingLocalId = Competition::query()->where('slug', $approvedSlug)->value('id');
                $existingLocalId = $existingLocalId !== null ? (int) $existingLocalId : null;
            }
        }

        $lpcEnroll = (int) DB::connection($conn)->table($this->connection->table('lpc_enrollments'))->count();
        $lpcSub = (int) DB::connection($conn)->table($this->connection->table('lpc_submissions'))->count();

        $competitions = DB::connection($conn)->table($this->connection->table('aq_competitions'))->orderBy('id')->get();
        $compReports = [];
        $unresolvedUsers = 0;
        $unresolvedCourse = 0;
        $participantWouldCreate = 0;
        $submissionWouldCreate = 0;
        $mediaPending = 0;
        $mergeRequired = 0;

        foreach ($competitions as $comp) {
            $legacyCompId = (string) $comp->id;
            $courseLegacyId = (int) ($comp->required_course_id ?? 0);
            $courseLocalId = $courseLegacyId > 0
                ? $this->maps->find('course', (string) $courseLegacyId)?->local_id
                : null;
            if ($courseLegacyId > 0 && $courseLocalId === null) {
                $unresolvedCourse++;
            }

            $existingCompMap = $this->maps->find('competition', $legacyCompId);
            $collisionLocal = null;
            if ($existingCompMap?->local_id === null) {
                $collisionLocal = Competition::query()
                    ->where('required_photos', (int) $comp->required_images)
                    ->orderBy('id')
                    ->first(['id', 'slug', 'status']);
                if ($collisionLocal !== null) {
                    $mergeRequired++;
                }
            }

            $enrollments = DB::connection($conn)
                ->table($this->connection->table('aq_enrollments'))
                ->where('competition_id', $comp->id)
                ->orderBy('id')
                ->get();

            $participantSamples = [];
            $partUnresolved = 0;
            foreach ($enrollments as $enr) {
                $userLegacyId = (string) $enr->user_id;
                $userLocalId = $this->maps->find('user', $userLegacyId)?->local_id;
                if ($userLocalId === null) {
                    $partUnresolved++;
                    $unresolvedUsers++;
                } else {
                    $participantWouldCreate++;
                }
                if (count($participantSamples) < 5) {
                    $participantSamples[] = [
                        'legacy_enrollment_id' => (string) $enr->id,
                        'user_legacy_id' => $userLegacyId,
                        'user_local_id' => $userLocalId,
                        'status' => $this->mapParticipantStatus((string) $enr->status),
                        'enrolled_at' => $enr->enrolled_at,
                        'resolution' => $userLocalId ? 'OK' : 'UNRESOLVED_USER_MAP',
                    ];
                }
            }

            $subSamples = [];
            $statusCounts = [];
            $subCount = 0;
            $orderByEnrollment = [];

            DB::connection($conn)
                ->table($this->connection->table('aq_submissions'))
                ->where('competition_id', $comp->id)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (
                    &$subCount,
                    &$submissionWouldCreate,
                    &$mediaPending,
                    &$statusCounts,
                    &$subSamples,
                    &$orderByEnrollment,
                    $conn,
                ): void {
                    foreach ($rows as $sub) {
                        $subCount++;
                        $st = (string) $sub->status;
                        $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
                        $submissionWouldCreate++;

                        $enrId = (int) $sub->enrollment_id;
                        $n = $orderByEnrollment[$enrId] ?? 0;
                        $orderByEnrollment[$enrId] = $n + 1;

                        $imageId = (int) ($sub->image_id ?? 0);
                        if ($imageId > 0) {
                            $mediaPending++;
                        }

                        if (count($subSamples) < 5) {
                            $subSamples[] = [
                                'legacy_submission_id' => (string) $sub->id,
                                'user_legacy_id' => (string) $sub->user_id,
                                'enrollment_legacy_id' => (string) $sub->enrollment_id,
                                'mapped_status' => $this->mapSubmissionStatus($st),
                                'source_status' => $st,
                                'sample_name' => $sub->sample_name,
                                'description' => $sub->description ?? null,
                                'scientific_info' => $sub->scientific_info ?? null,
                                'submitted_at' => $sub->created_at ?? null,
                                'reviewed_at' => $sub->reviewed_at ?? null,
                                'source_order_index' => $n,
                                'sample_number' => null,
                                'photo_index' => null,
                                'slot_mapping' => self::SLOT_SCHEMA_RESOLVED,
                                'media' => [
                                    'status' => 'MEDIA_PENDING',
                                    'attachment_id' => $imageId > 0 ? (string) $imageId : null,
                                    'relative_path' => $imageId > 0 ? $this->attachmentPath($conn, $imageId) : null,
                                ],
                            ];
                        }
                    }
                }, 'id');

            $compReports[] = [
                'legacy_id' => $legacyCompId,
                'title' => $comp->title,
                'required_images' => (int) $comp->required_images,
                'max_images_per_sample' => (int) $comp->max_images_per_sample,
                'required_photos_laravel' => (int) $comp->required_images,
                'photos_per_sample_laravel' => (int) $comp->max_images_per_sample,
                'start_date' => $comp->start_date,
                'end_date' => $comp->end_date,
                'status' => $comp->status,
                'required_course_legacy_id' => $courseLegacyId > 0 ? (string) $courseLegacyId : null,
                'required_course_local_id' => $courseLocalId,
                'course_resolution' => $courseLegacyId > 0
                    ? ($courseLocalId ? 'RESOLVED' : 'UNRESOLVED_COURSE_MAP')
                    : 'ABSENT',
                'collision' => $collisionLocal ? [
                    'class' => ($existingCompMap?->local_id)
                        ? 'SKIPPED_MAPPED'
                        : (($predictedDecision === self::DECISION_MAP_EXISTING)
                            ? MigrationImportOutcome::WOULD_MAP_EXISTING
                            : 'MERGE_REQUIRES_DECISION'),
                    'local_id' => $collisionLocal->id,
                    'slug' => $collisionLocal->slug,
                    'status' => $collisionLocal->status,
                    'approved_decision' => $approvedDecision,
                    'operator_decision' => $decision,
                    'supported_decisions' => [self::DECISION_MAP_EXISTING, self::DECISION_CREATE_NEW],
                ] : ($existingCompMap?->local_id ? [
                    'class' => 'SKIPPED_MAPPED',
                    'local_id' => $existingCompMap->local_id,
                ] : null),
                'predicted_outcome' => MigrationImportOutcome::classifyCompetitionShell(
                    $existingCompMap?->local_id !== null,
                    $collisionLocal !== null || Competition::query()->where('required_photos', (int) $comp->required_images)->exists(),
                    $predictedDecision,
                    $courseLocalId !== null || $courseLegacyId <= 0,
                ),
                'participants' => $enrollments->count(),
                'participant_unresolved_users' => $partUnresolved,
                'submissions' => $subCount,
                'submission_status_counts' => $statusCounts,
                'participant_samples' => $participantSamples,
                'submission_samples' => $subSamples,
                'mapping' => [
                    'title' => 'competitions.title.ar (CONFIRMED)',
                    'description' => 'competitions.description.ar (CONFIRMED)',
                    'required_images' => 'competitions.required_photos (CONFIRMED=100)',
                    'max_images_per_sample' => 'competitions.photos_per_sample / max_photos_per_sample (CONFIRMED)',
                    'start_date/end_date' => 'starts_at / ends_at (CONFIRMED)',
                    'status active' => "competitions.status='active' (CONFIRMED)",
                    'required_course_id' => 'prerequisite_course_id via course map (CONFIRMED key; unresolved until courses imported)',
                    'rules' => 'NULL in source → NOT PRESENT',
                    'prize_description' => 'empty string → NOT PRESENT',
                ],
            ];
        }

        $write = [
            'competitions_created' => 0,
            'competitions_mapped_existing' => 0,
            'participants_created' => 0,
            'submissions_created' => 0,
            'submissions_blocked' => 0,
            'merge_required' => $mergeRequired,
            'decision_required' => 0,
            'skipped_mapped' => 0,
            'unresolved_users' => $unresolvedUsers,
            'unresolved_course' => $unresolvedCourse,
            'slot_schema_blocker' => false,
        ];

        if (! $dryRun) {
            $write = $this->persistAll($competitions, $conn, $decision, $existingLocalId);
        }

        $mapsReady = $write['unresolved_users'] === 0 && $write['unresolved_course'] === 0;
        $wrote = ! $dryRun && (
            $write['competitions_created']
            + $write['competitions_mapped_existing']
            + $write['participants_created']
            + $write['submissions_created']
        ) > 0;

        $decisionBlocked = ! $dryRun
            && ($write['decision_required'] ?? 0) > 0
            && $write['competitions_created'] === 0
            && $write['competitions_mapped_existing'] === 0
            && ($write['skipped_mapped'] ?? 0) === 0;

        $blocked = ! $dryRun && ! $wrote && (
            $decisionBlocked
            || $write['unresolved_course'] > 0
            || $write['unresolved_users'] > 0
            || (($write['merge_required'] > 0) && $decision === null)
        );

        $code = null;
        if (! $dryRun) {
            if ($decisionBlocked) {
                $code = 'COMPETITION_DECISION_REQUIRED';
            } elseif ($write['unresolved_course'] > 0 && $write['competitions_created'] === 0 && $write['competitions_mapped_existing'] === 0) {
                $code = 'UNRESOLVED_COURSE_MAP';
            } elseif ($write['unresolved_users'] > 0 && $write['participants_created'] === 0) {
                $code = 'UNRESOLVED_USER_MAP';
            } elseif ($write['submissions_created'] > 0 || ($write['submissions_blocked'] ?? 0) === 0) {
                $code = self::SLOT_SCHEMA_RESOLVED;
            }
        }

        return [
            'status' => $dryRun ? 'ok' : ($blocked && ! $wrote ? 'blocked' : 'ok'),
            'code' => $code,
            'entity_type' => 'competition',
            'dry_run' => $dryRun,
            'source' => 'AQ (wp_aq_*)',
            'expected' => [
                'competitions' => 1,
                'participants' => 31,
                'submissions' => 900,
            ],
            'lpc_ignored' => [
                'enrollments' => $lpcEnroll,
                'submissions' => $lpcSub,
                'policy' => 'LPC never merged',
            ],
            'competition_decision' => $decision,
            'competition_decision_required' => $decision === null,
            'supported_decisions' => [self::DECISION_MAP_EXISTING, self::DECISION_CREATE_NEW],
            'existing_local_id_option' => $existingLocalId,
            'would_create' => $dryRun
                ? (($decision === self::DECISION_CREATE_NEW && $mergeRequired === 0) ? count($competitions) : 0)
                : 0,
            'would_map_existing' => $dryRun
                ? (($decision === self::DECISION_MAP_EXISTING) ? count($competitions) : 0)
                : 0,
            'would_skip' => $dryRun ? 0 : 0,
            'would_defer' => $dryRun && $decision === null ? count($competitions) : 0,
            'would_fail' => $dryRun && $decision === self::DECISION_CREATE_NEW && $mergeRequired > 0
                ? $mergeRequired
                : 0,
            'competitions' => count($compReports),
            'competition_reports' => $compReports,
            'participants_total' => $participantWouldCreate + ($dryRun ? $unresolvedUsers : 0),
            'participants_would_create_when_user_mapped' => $participantWouldCreate,
            'unresolved_users' => $write['unresolved_users'],
            'unresolved_prerequisite_courses' => $write['unresolved_course'],
            'submissions_total' => $submissionWouldCreate,
            'media_pending_submissions' => $mediaPending,
            'merge_requires_decision' => $write['merge_required'],
            'competitions_created' => $dryRun ? 0 : $write['competitions_created'],
            'competitions_mapped_existing' => $dryRun ? 0 : $write['competitions_mapped_existing'],
            'participants_created' => $dryRun ? 0 : $write['participants_created'],
            'submissions_created' => $dryRun ? 0 : $write['submissions_created'],
            'submissions_blocked_by_slot_schema' => $dryRun ? 0 : $write['submissions_blocked'],
            'skipped_mapped' => $dryRun ? 0 : $write['skipped_mapped'],
            'created' => $dryRun ? 0 : $write['competitions_created'],
            'slot_mapping_verdict' => self::SLOT_SCHEMA_RESOLVED,
            'slot_mapping_policy' => 'Never invent sample_number/photo_index. Persist historical rows with NULL slots; preserve sample_name + source_order_index + image_id metadata.',
            'slot_schema_blocker' => null,
            'slot_schema' => [
                'code' => self::SLOT_SCHEMA_RESOLVED,
                'reason' => 'competition_submissions.sample_number and photo_index are nullable for historical AQ rows; uk_submission_slot still enforces uniqueness for assigned slots',
                'sample_name_to_slot_mapping' => 'AMBIGUOUS — not invented',
                'invent_sample_number' => false,
                'invent_photo_index' => false,
            ],
            'wrote_to_database' => $wrote,
            'ready_for_real_import' => false,
            'maps_ready' => $mapsReady,
            'side_effects' => [
                'email' => false,
                'whatsapp' => false,
                'payment' => false,
                'orders' => false,
                'bosta' => false,
                'competition_submission_approved_event' => false,
                'mechanism' => 'Direct Eloquent create for Competition/Participant/Submission; null slots; no Spatie media attach',
            ],
            'ready_after' => [
                'user_maps' => $write['unresolved_users'] === 0,
                'course_maps' => $write['unresolved_course'] === 0,
                'competition_decision' => $decision !== null || ($write['skipped_mapped'] ?? 0) > 0,
                'uploads_directory' => false,
                'slot_schema_change_or_approved_slot_rule' => true,
            ],
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $competitions
     * @return array<string, int|bool>
     */
    private function persistAll(
        $competitions,
        string $conn,
        ?string $decision,
        ?int $existingLocalId,
    ): array {
        $competitionsCreated = 0;
        $competitionsMappedExisting = 0;
        $participantsCreated = 0;
        $submissionsCreated = 0;
        $submissionsBlocked = 0;
        $mergeRequired = 0;
        $decisionRequired = 0;
        $skippedMapped = 0;
        $unresolvedUsers = 0;
        $unresolvedCourse = 0;

        foreach ($competitions as $comp) {
            $legacyCompId = (string) $comp->id;
            $courseLegacyId = (int) ($comp->required_course_id ?? 0);
            $courseLocalId = $courseLegacyId > 0
                ? $this->maps->find('course', (string) $courseLegacyId)?->local_id
                : null;
            if ($courseLegacyId > 0 && $courseLocalId === null) {
                $unresolvedCourse++;
            }

            $resolved = $this->resolveCompetitionLocalId(
                $comp,
                $decision,
                $existingLocalId,
                $courseLocalId !== null ? (int) $courseLocalId : null,
            );

            if ($resolved['status'] === 'skipped_mapped') {
                $skippedMapped++;
                $competitionLocalId = (int) $resolved['local_id'];
            } elseif ($resolved['status'] === 'mapped_existing') {
                $competitionsMappedExisting++;
                $competitionLocalId = (int) $resolved['local_id'];
            } elseif ($resolved['status'] === 'created') {
                $competitionsCreated++;
                $competitionLocalId = (int) $resolved['local_id'];
            } elseif ($resolved['status'] === 'decision_required') {
                $decisionRequired++;
                $mergeRequired++;

                continue;
            } elseif ($resolved['status'] === 'unresolved_course') {
                continue;
            } else {
                continue;
            }

            $enrollments = DB::connection($conn)
                ->table($this->connection->table('aq_enrollments'))
                ->where('competition_id', $comp->id)
                ->orderBy('id')
                ->get();

            $participantLocalByEnrollment = [];
            foreach ($enrollments as $enr) {
                $userLegacyId = (string) $enr->user_id;
                $userLocalId = $this->maps->find('user', $userLegacyId)?->local_id;
                if ($userLocalId === null) {
                    $unresolvedUsers++;

                    continue;
                }
                $result = $this->persistParticipant(
                    (string) $enr->id,
                    $competitionLocalId,
                    (int) $userLocalId,
                    $userLegacyId,
                    (string) $enr->status,
                    $enr->enrolled_at,
                );
                $participantLocalByEnrollment[(int) $enr->id] = $result['id'];
                if ($result['created']) {
                    $participantsCreated++;
                }
            }

            $orderByEnrollment = [];
            $touchedParticipants = [];
            DB::connection($conn)
                ->table($this->connection->table('aq_submissions'))
                ->where('competition_id', $comp->id)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (
                    &$submissionsCreated,
                    &$orderByEnrollment,
                    &$touchedParticipants,
                    $participantLocalByEnrollment,
                    $competitionLocalId,
                    $conn,
                ): void {
                    foreach ($rows as $sub) {
                        $enrId = (int) $sub->enrollment_id;
                        $participantId = $participantLocalByEnrollment[$enrId] ?? null;
                        if ($participantId === null) {
                            continue;
                        }
                        $sourceOrder = $orderByEnrollment[$enrId] ?? 0;
                        $orderByEnrollment[$enrId] = $sourceOrder + 1;

                        $result = $this->persistHistoricalSubmission(
                            $sub,
                            $participantId,
                            $competitionLocalId,
                            $sourceOrder,
                            $conn,
                        );
                        if ($result['created']) {
                            $submissionsCreated++;
                            $touchedParticipants[$participantId] = true;
                        }
                    }
                }, 'id');

            foreach (array_keys($touchedParticipants) as $participantId) {
                $participant = CompetitionParticipant::query()->find($participantId);
                if ($participant !== null) {
                    $this->progress->recalculate($participant);
                }
            }
        }

        return [
            'competitions_created' => $competitionsCreated,
            'competitions_mapped_existing' => $competitionsMappedExisting,
            'participants_created' => $participantsCreated,
            'submissions_created' => $submissionsCreated,
            'submissions_blocked' => $submissionsBlocked,
            'merge_required' => $mergeRequired,
            'decision_required' => $decisionRequired,
            'skipped_mapped' => $skippedMapped,
            'unresolved_users' => $unresolvedUsers,
            'unresolved_course' => $unresolvedCourse,
            'slot_schema_blocker' => false,
        ];
    }

    /**
     * @return array{status: string, local_id?: int, code?: string}
     */
    public function resolveCompetitionLocalId(
        object $comp,
        ?string $decision,
        ?int $existingLocalId,
        ?int $courseLocalId,
    ): array {
        $legacyId = (string) $comp->id;
        $existing = $this->maps->find('competition', $legacyId);
        if ($existing?->local_id) {
            return ['status' => 'skipped_mapped', 'local_id' => (int) $existing->local_id];
        }

        $decision = $this->normalizeDecision($decision);
        if ($decision === null) {
            return ['status' => 'decision_required', 'code' => 'COMPETITION_DECISION_REQUIRED'];
        }

        if ($decision === self::DECISION_MAP_EXISTING) {
            $targetId = $existingLocalId;
            if ($targetId === null) {
                $approvedSlug = $this->approvedCollisions->approvedCompetitionLocalSlug($legacyId);
                if ($approvedSlug !== null) {
                    $targetId = Competition::query()->where('slug', $approvedSlug)->value('id');
                    $targetId = $targetId !== null ? (int) $targetId : null;
                }
            }
            if ($targetId === null) {
                $collision = Competition::query()
                    ->where('required_photos', (int) $comp->required_images)
                    ->orderBy('id')
                    ->first(['id']);
                $targetId = $collision?->id;
            }
            if ($targetId === null || ! Competition::query()->whereKey($targetId)->exists()) {
                return ['status' => 'decision_required', 'code' => 'MAP_EXISTING_REQUIRES_LOCAL_ID'];
            }

            $this->maps->upsertMapping('competition', $legacyId, [
                'local_id' => $targetId,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                    'legacy_wordpress_id' => $legacyId,
                    'collision_decision' => self::DECISION_MAP_EXISTING,
                    'overwrite' => false,
                    'required_course_legacy_id' => (string) ($comp->required_course_id ?? ''),
                    'source' => 'aq_competitions',
                    'rollback_policy' => 'unmap_only_never_delete_competition',
                ]),
            ]);

            return ['status' => 'mapped_existing', 'local_id' => (int) $targetId];
        }

        // CREATE_NEW
        if ($courseLocalId === null) {
            return ['status' => 'unresolved_course', 'code' => 'UNRESOLVED_COURSE_MAP'];
        }

        $createdId = $this->persistCompetition($comp, $courseLocalId, self::DECISION_CREATE_NEW);
        if ($createdId === null) {
            return ['status' => 'failed'];
        }

        return ['status' => 'created', 'local_id' => $createdId];
    }

    private function persistCompetition(object $comp, int $courseLocalId, string $decision = self::DECISION_CREATE_NEW): ?int
    {
        $legacyId = (string) $comp->id;
        $existing = $this->maps->find('competition', $legacyId);
        if ($existing?->local_id) {
            return (int) $existing->local_id;
        }

        $slugBase = Str::slug((string) $comp->title);
        if ($slugBase === '') {
            $slugBase = 'aq-competition-'.$legacyId;
        }
        $slug = $slugBase;
        if (Competition::query()->where('slug', $slug)->exists()) {
            $slug = 'aq-competition-'.$legacyId;
        }

        $competition = Competition::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => $slug,
            'prerequisite_course_id' => $courseLocalId,
            'required_photos' => (int) $comp->required_images,
            'photos_per_sample' => (int) $comp->max_images_per_sample,
            'max_photos_per_sample' => (int) $comp->max_images_per_sample,
            'starts_at' => $comp->start_date,
            'ends_at' => $comp->end_date,
            'status' => (string) ($comp->status ?: 'active'),
            'prize_description' => filled($comp->prize_description ?? null) ? (string) $comp->prize_description : null,
            'title' => ['ar' => (string) $comp->title],
            'description' => filled($comp->description ?? null) ? ['ar' => (string) $comp->description] : null,
            'rules' => filled($comp->rules ?? null) ? ['ar' => (string) $comp->rules] : null,
        ]);

        $this->maps->upsertMapping('competition', $legacyId, [
            'local_id' => $competition->id,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'legacy_wordpress_id' => $legacyId,
                'required_course_legacy_id' => (string) ($comp->required_course_id ?? ''),
                'collision_decision' => $decision,
                'source' => 'aq_competitions',
            ]),
        ]);

        return (int) $competition->id;
    }

    /**
     * @return array{id: int, created: bool}
     */
    public function persistParticipant(
        string $legacyEnrollmentId,
        int $competitionLocalId,
        int $userLocalId,
        string $userLegacyId,
        string $status,
        mixed $enrolledAt,
    ): array {
        $existingMap = $this->maps->find('competition_participant', $legacyEnrollmentId);
        if ($existingMap?->local_id) {
            return ['id' => (int) $existingMap->local_id, 'created' => false];
        }

        $existing = CompetitionParticipant::query()
            ->where('competition_id', $competitionLocalId)
            ->where('user_id', $userLocalId)
            ->first();

        if ($existing !== null) {
            $this->maps->upsertMapping('competition_participant', $legacyEnrollmentId, [
                'local_id' => $existing->id,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                    'user_legacy_id' => $userLegacyId,
                    'collision' => 'EXISTING_PARTICIPANT_PAIR',
                    'source_status' => $status,
                    'registered_at_source' => $enrolledAt,
                ]),
            ]);

            return ['id' => (int) $existing->id, 'created' => false];
        }

        $participant = CompetitionParticipant::query()->create([
            'competition_id' => $competitionLocalId,
            'user_id' => $userLocalId,
            'status' => $this->mapParticipantStatus($status),
            'approved_count' => 0,
            'pending_count' => 0,
            'rejected_count' => 0,
            'registered_at' => $enrolledAt ?: now(),
        ]);

        $this->maps->upsertMapping('competition_participant', $legacyEnrollmentId, [
            'local_id' => $participant->id,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'user_legacy_id' => $userLegacyId,
                'legacy_enrollment_id' => $legacyEnrollmentId,
                'source_status' => $status,
            ]),
        ]);

        return ['id' => (int) $participant->id, 'created' => true];
    }

    /**
     * Persist a historical AQ submission without inventing sample_number / photo_index.
     * Media is never physically imported (MEDIA_PENDING, physical_media_imported=false).
     * Does not fire CompetitionSubmissionApproved.
     *
     * @return array{created: bool, code: string, local_id?: int}
     */
    public function persistHistoricalSubmission(
        object $sub,
        int $participantId,
        int $competitionLocalId,
        int $sourceOrderIndex,
        ?string $conn = null,
    ): array {
        $legacyId = (string) $sub->id;
        $existingMap = $this->maps->find('competition_submission', $legacyId);
        if ($existingMap?->local_id) {
            return [
                'created' => false,
                'code' => 'SKIPPED_MAPPED',
                'local_id' => (int) $existingMap->local_id,
            ];
        }

        $imageId = (int) ($sub->image_id ?? 0);
        $relativePath = null;
        if ($imageId > 0 && $conn !== null) {
            $relativePath = $this->attachmentPath($conn, $imageId);
        }

        $reviewedByLocal = null;
        $reviewedByLegacy = $sub->reviewed_by ?? null;
        if ($reviewedByLegacy !== null && $reviewedByLegacy !== '' && (int) $reviewedByLegacy > 0) {
            $reviewedByLocal = $this->maps->find('user', (string) $reviewedByLegacy)?->local_id;
        }

        $mappedStatus = $this->mapSubmissionStatus((string) ($sub->status ?? 'pending'));

        // Explicit null slots — never invent semantic sample_number / photo_index.
        $submission = CompetitionSubmission::query()->create([
            'participant_id' => $participantId,
            'sample_number' => null,
            'photo_index' => null,
            'sample_name' => filled($sub->sample_name ?? null) ? (string) $sub->sample_name : null,
            'status' => $mappedStatus,
            'description' => $sub->description ?? null,
            'scientific_notes' => $sub->scientific_info ?? null,
            'submitted_at' => $sub->created_at ?? now(),
            'reviewed_at' => $sub->reviewed_at ?? null,
            'reviewed_by' => $reviewedByLocal,
        ]);

        // Do NOT import physical media / Spatie attach — keep MEDIA_PENDING metadata only.
        $this->maps->upsertMapping('competition_submission', $legacyId, [
            'local_id' => $submission->id,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'legacy_submission_id' => $legacyId,
                'participant_local_id' => $participantId,
                'competition_local_id' => $competitionLocalId,
                'description' => $sub->description ?? null,
                'scientific_info' => $sub->scientific_info ?? null,
                'scientific_notes_target' => 'scientific_notes',
                'source_status' => $sub->status ?? null,
                'mapped_status' => $mappedStatus,
                'submitted_at' => $sub->created_at ?? null,
                'reviewed_at' => $sub->reviewed_at ?? null,
                'reviewed_by_legacy' => $reviewedByLegacy !== null && $reviewedByLegacy !== '' ? (string) $reviewedByLegacy : null,
                'reviewed_by_local' => $reviewedByLocal,
                'sample_name' => $sub->sample_name ?? null,
                'source_order_index' => $sourceOrderIndex,
                'sample_number' => null,
                'photo_index' => null,
                'image_id' => $imageId > 0 ? (string) $imageId : null,
                'relative_path' => $relativePath,
                'media_status' => 'MEDIA_PENDING',
                'physical_media_imported' => false,
                'code' => self::SLOT_SCHEMA_RESOLVED,
                'invent_sample_number' => false,
                'invent_photo_index' => false,
            ]),
        ]);

        return [
            'created' => true,
            'code' => self::SLOT_SCHEMA_RESOLVED,
            'local_id' => (int) $submission->id,
        ];
    }

    /**
     * @deprecated Use persistHistoricalSubmission. Kept for call-site compatibility.
     *
     * @return bool true when a new row was created
     */
    public function persistSubmissionMetadataOnly(
        object $sub,
        int $participantId,
        int $competitionLocalId,
        int $sourceOrderIndex,
        ?string $conn = null,
    ): bool {
        $result = $this->persistHistoricalSubmission(
            $sub,
            $participantId,
            $competitionLocalId,
            $sourceOrderIndex,
            $conn,
        );

        return $result['created'];
    }

    /**
     * Persist historical submission with null slots (never invents sample_number/photo_index).
     *
     * @return array{created: bool, code: string}
     */
    public function persistSubmission(
        object $sub,
        int $participantId,
        int $sampleNumber,
        int $photoIndex,
        int $sourceOrderIndex,
        int $maxPerSample,
        ?string $conn = null,
    ): array {
        // Intentionally ignore invented/provisional slot arguments.
        unset($sampleNumber, $photoIndex, $maxPerSample);

        $competitionLocalId = CompetitionParticipant::query()
            ->whereKey($participantId)
            ->value('competition_id');

        $result = $this->persistHistoricalSubmission(
            $sub,
            $participantId,
            (int) ($competitionLocalId ?? 0),
            $sourceOrderIndex,
            $conn,
        );

        return ['created' => $result['created'], 'code' => $result['code']];
    }

    /**
     * Silent helper for tests — create competition shell from array payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function persistCompetitionFromPayload(array $payload): ?Competition
    {
        $legacyId = (string) $payload['legacy_id'];
        $existing = $this->maps->find('competition', $legacyId);
        if ($existing?->local_id) {
            return Competition::query()->find($existing->local_id);
        }

        $decision = $this->normalizeDecision($payload['decision'] ?? self::DECISION_CREATE_NEW);
        if ($decision === self::DECISION_MAP_EXISTING) {
            $localId = (int) ($payload['existing_local_id'] ?? 0);
            if ($localId <= 0) {
                return null;
            }
            $comp = (object) [
                'id' => $legacyId,
                'required_images' => $payload['required_photos'] ?? 100,
                'required_course_id' => $payload['required_course_legacy_id'] ?? null,
            ];
            $resolved = $this->resolveCompetitionLocalId($comp, self::DECISION_MAP_EXISTING, $localId, null);

            return isset($resolved['local_id'])
                ? Competition::query()->find($resolved['local_id'])
                : null;
        }

        $comp = (object) [
            'id' => $legacyId,
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'required_images' => $payload['required_photos'],
            'max_images_per_sample' => $payload['photos_per_sample'],
            'start_date' => $payload['starts_at'],
            'end_date' => $payload['ends_at'],
            'status' => $payload['status'] ?? 'active',
            'required_course_id' => $payload['required_course_legacy_id'] ?? null,
            'prize_description' => $payload['prize_description'] ?? null,
            'rules' => $payload['rules'] ?? null,
        ];

        $id = $this->persistCompetition($comp, (int) $payload['prerequisite_course_id'], self::DECISION_CREATE_NEW);

        return $id ? Competition::query()->find($id) : null;
    }

    private function normalizeDecision(mixed $decision): ?string
    {
        if (! is_string($decision) || $decision === '') {
            return null;
        }
        $decision = strtoupper(trim($decision));

        return in_array($decision, [self::DECISION_MAP_EXISTING, self::DECISION_CREATE_NEW], true)
            ? $decision
            : null;
    }

    private function mapParticipantStatus(string $status): string
    {
        return match ($status) {
            'active' => ParticipantStatus::Active->value,
            'registered' => ParticipantStatus::Registered->value,
            'shortlisted' => ParticipantStatus::Shortlisted->value,
            'winner' => ParticipantStatus::Winner->value,
            'disqualified' => ParticipantStatus::Disqualified->value,
            default => ParticipantStatus::Registered->value,
        };
    }

    private function mapSubmissionStatus(string $status): string
    {
        return match ($status) {
            'approved' => SubmissionStatus::Approved->value,
            'pending' => SubmissionStatus::Pending->value,
            'rejected' => SubmissionStatus::Rejected->value,
            'revision_requested' => SubmissionStatus::RevisionRequested->value,
            default => SubmissionStatus::Pending->value,
        };
    }

    private function attachmentPath(string $conn, int $attachmentId): ?string
    {
        $file = DB::connection($conn)
            ->table($this->connection->table('postmeta'))
            ->where('post_id', $attachmentId)
            ->where('meta_key', '_wp_attached_file')
            ->value('meta_value');

        return $file ? (string) $file : null;
    }
}
