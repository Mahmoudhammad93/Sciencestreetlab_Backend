<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizOfficialScore;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Historical LearnDash quiz attempt importer (definitions must already be mapped).
 *
 * NEVER uses QuizAttemptService::submit (would fire QuizPassed / progress side effects).
 * Default dry-run. Real writes require WORDPRESS_REAL_PERSIST + active migration run
 * + allow_real_attempt_persist arming from the execute command path.
 */
final class WordPressQuizAttemptImporter
{
    public const ENTITY_ATTEMPT = WordPressQuizAttemptHistoryAnalyzer::ENTITY_ATTEMPT;

    public const ENTITY_ATTEMPT_ANSWER = WordPressQuizAttemptHistoryAnalyzer::ENTITY_ATTEMPT_ANSWER;

    public const DEFAULT_CHUNK_SIZE = 50;

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly WordPressQuizAttemptHistoryAnalyzer $analyzer,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
        private readonly MigrationRunService $runs,
        private readonly WordPressHistoricalQuizAnswerNormalizer $answerNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $maps
     * @return array<string, mixed>
     */
    public function import(int $migrationRunId, bool $dryRun = true, array $maps = []): array
    {
        if (! $dryRun && $this->persistGate->realPersistEnabled()) {
            $this->runs->bindRunning($migrationRunId);
        }

        $blocked = $this->persistGate->importerBlockIfUnauthorized($dryRun, self::ENTITY_ATTEMPT);
        if ($blocked !== null) {
            return array_merge([
                'status' => 'blocked',
                'migration_run_id' => $migrationRunId,
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'database_writes' => 0,
                'side_effects' => false,
            ], $blocked);
        }

        $maps = $this->hydrateMaps($maps);
        $planMaps = array_merge($maps, ['include_candidates' => ! $dryRun || ($maps['include_candidates'] ?? false)]);
        // Dry-run summary does not need full candidate dump unless requested.
        if ($dryRun && ! ($maps['include_candidates'] ?? false)) {
            $planMaps['include_candidates'] = false;
        } else {
            $planMaps['include_candidates'] = true;
        }

        $analysis = $this->analyzer->analyze($planMaps);
        if (($analysis['status'] ?? '') !== 'ok') {
            return $analysis;
        }

        $dry = $analysis['dry_run_totals'];
        $matrix = $analysis['matrix'];

        $report = [
            'status' => $dryRun ? 'dry_run' : 'ok',
            'migration_run_id' => $migrationRunId,
            'dry_run' => $dryRun,
            'wrote_to_database' => false,
            'database_writes' => 0,
            'side_effects' => false,
            'events_dispatched' => 0,
            'progress_mutations' => 0,
            'emails_sent' => 0,
            'notifications_sent' => 0,
            'official_score_mutations' => 0,
            'external_http' => 0,
            'analysis_matrix' => $matrix,
            'dry_run_totals' => $dry,
            'would_create_attempts' => $dry['WOULD_CREATE_ATTEMPTS'],
            'would_create_attempt_answers' => $dry['WOULD_CREATE_ATTEMPT_ANSWERS'],
            'would_create_answers' => $dry['WOULD_CREATE_ATTEMPT_ANSWERS'],
            'deferred_user' => $dry['DEFERRED_USER'],
            'deferred_enrollment' => $dry['DEFERRED_MISSING_ENROLLMENT'],
            'skipped_outside_scope' => $dry['SKIPPED_OUTSIDE_SCOPE'],
            'ambiguous' => $matrix['AMBIGUOUS'] ?? 0,
            'conflicts' => $dry['CONFLICTS'] ?? 0,
            'duplicate_source_keys' => $dry['DUPLICATE_SOURCE_KEYS'] ?? 0,
            'applied_attempts' => 0,
            'applied_answers' => 0,
            'skipped_mapped' => 0,
            'deferred_answers' => 0,
            'chunks_committed' => 0,
            'last_successful_chunk' => null,
            'last_successful_legacy_key' => null,
            'note' => 'Historical import inserts QuizAttempt rows directly under Event::fake; never QuizAttemptService::submit.',
        ];

        if (($matrix['AMBIGUOUS'] ?? 0) > 0) {
            return array_merge($report, [
                'status' => 'blocked',
                'code' => 'AMBIGUOUS_ATTEMPTS_PRESENT',
                'message' => 'AMBIGUOUS > 0 — refuse historical persist.',
            ]);
        }

        if (($dry['DUPLICATE_SOURCE_KEYS'] ?? 0) > 0) {
            return array_merge($report, [
                'status' => 'blocked',
                'code' => 'DUPLICATE_SOURCE_KEYS',
                'message' => 'Duplicate reconstructed legacy keys — refuse persist.',
            ]);
        }

        if ($dryRun) {
            return $report;
        }

        if (($maps['quiz_maps'] ?? []) === []) {
            return array_merge($report, [
                'status' => 'blocked',
                'code' => 'QUIZ_DEFINITION_MAPS_REQUIRED',
                'message' => 'Import quiz definitions first; quiz_maps required for attempt persist.',
            ]);
        }

        if (! ($maps['allow_real_attempt_persist'] ?? false)) {
            return array_merge($report, [
                'status' => 'blocked',
                'code' => 'ATTEMPT_PERSIST_NOT_ARMED',
                'message' => 'Dry-run ready. Arm allow_real_attempt_persist only after authorized execute.',
            ]);
        }

        $candidates = $analysis['candidates'] ?? [];
        if ($candidates === []) {
            // Re-run with candidates if analyze was called without them.
            $analysis = $this->analyzer->analyze(array_merge($maps, ['include_candidates' => true]));
            $candidates = $analysis['candidates'] ?? [];
            $report['dry_run_totals'] = $analysis['dry_run_totals'];
            $report['analysis_matrix'] = $analysis['matrix'];
        }

        $officialBefore = QuizOfficialScore::query()->count();
        $lessonBefore = (int) DB::table('lesson_completions')->count();
        $topicBefore = (int) DB::table('topic_completions')->count();
        $httpBefore = $this->httpSnapshot();

        Event::fake([QuizPassed::class]);
        Mail::fake();
        Notification::fake();

        $chunkSize = max(1, (int) ($maps['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE));
        $attemptNumbers = $this->seedAttemptNumbers($candidates);

        $appliedAttempts = 0;
        $appliedAnswers = 0;
        $skippedMapped = 0;
        $deferredAnswers = 0;
        $chunksCommitted = 0;
        $lastSuccessfulLegacyKey = null;
        $writes = 0;

        try {
            foreach (array_chunk($candidates, $chunkSize) as $chunkIndex => $chunk) {
                DB::transaction(function () use (
                    $chunk,
                    $migrationRunId,
                    $maps,
                    &$attemptNumbers,
                    &$appliedAttempts,
                    &$appliedAnswers,
                    &$skippedMapped,
                    &$deferredAnswers,
                    &$writes,
                    &$lastSuccessfulLegacyKey,
                ): void {
                    foreach ($chunk as $candidate) {
                        $legacyKey = (string) $candidate['legacy_key'];
                        $existing = $this->maps->find(self::ENTITY_ATTEMPT, $legacyKey);
                        if ($existing?->local_id) {
                            $skippedMapped++;
                            $lastSuccessfulLegacyKey = $legacyKey;

                            continue;
                        }

                        $localQuizId = $candidate['local_quiz_id'] ?? ($maps['quiz_maps'][(string) $candidate['legacy_quiz_id']] ?? null);
                        if ($localQuizId === null) {
                            // Should have been deferred in plan; skip defensively.
                            continue;
                        }

                        $uq = $candidate['local_user_id'].'|'.$localQuizId;
                        $attemptNumbers[$uq] = ($attemptNumbers[$uq] ?? 0) + 1;

                        $timestamps = $this->resolveTimestamps($candidate);
                        $scores = $this->resolveScores($candidate);

                        $attempt = QuizAttempt::query()->create([
                            'quiz_id' => (int) $localQuizId,
                            'user_id' => (int) $candidate['local_user_id'],
                            'enrollment_id' => $candidate['enrollment_id'],
                            'attempt_number' => $attemptNumbers[$uq],
                            'status' => AttemptStatus::Graded,
                            'score' => $scores['score'],
                            'max_score' => $scores['max_score'],
                            'percentage' => $scores['percentage'],
                            'passed' => $scores['passed'],
                            'is_official' => false,
                            'started_at' => $timestamps['started_at'],
                            'submitted_at' => $timestamps['submitted_at'],
                            'graded_at' => $timestamps['graded_at'],
                            'time_spent_seconds' => $timestamps['time_spent_seconds'],
                        ]);
                        $writes++;
                        $appliedAttempts++;

                        $this->maps->upsertMapping(self::ENTITY_ATTEMPT, $legacyKey, [
                            'migration_run_id' => $migrationRunId,
                            'local_id' => $attempt->id,
                            'imported_at' => now(),
                            'metadata' => LegacyImportMapRepository::ownershipCreated([
                                'historical_import' => true,
                                'correlation' => $candidate['correlation'],
                                'statistic_ref_id' => $candidate['statistic_ref_id'],
                                'activity_id' => $candidate['activity_id'],
                                'legacy_user_id' => $candidate['legacy_user_id'],
                                'legacy_quiz_id' => $candidate['legacy_quiz_id'],
                                'legacy_course_id' => $candidate['legacy_course_id'],
                                'score_authority' => $scores['score_authority'],
                                'source_score_disagreement' => $scores['source_score_disagreement'],
                                'pass_authority' => $candidate['pass_authority'],
                                'nullable_enrollment' => $candidate['enrollment_id'] === null,
                            ]),
                        ]);
                        $writes++;

                        if (! empty($candidate['statistic_ref_id'])) {
                            $answerResult = $this->persistAnswersForRef(
                                $migrationRunId,
                                $attempt,
                                (int) $candidate['statistic_ref_id'],
                                $maps['question_maps'] ?? [],
                            );
                            $appliedAnswers += $answerResult['applied'];
                            $deferredAnswers += $answerResult['deferred'];
                            $writes += $answerResult['writes'];
                        }

                        $lastSuccessfulLegacyKey = $legacyKey;
                    }
                });

                $chunksCommitted++;
                $report['last_successful_chunk'] = $chunkIndex;
                $report['last_successful_legacy_key'] = $lastSuccessfulLegacyKey;
            }
        } catch (Throwable $e) {
            return array_merge($report, [
                'status' => 'partial_failure',
                'code' => 'CHUNK_PERSIST_FAILED',
                'message' => $e->getMessage(),
                'wrote_to_database' => $chunksCommitted > 0 || $appliedAttempts > 0,
                'database_writes' => $writes,
                'applied_attempts' => $appliedAttempts,
                'applied_answers' => $appliedAnswers,
                'skipped_mapped' => $skippedMapped,
                'deferred_answers' => $deferredAnswers,
                'chunks_committed' => $chunksCommitted,
                'last_successful_chunk' => $report['last_successful_chunk'],
                'last_successful_legacy_key' => $lastSuccessfulLegacyKey,
            ]);
        }

        $officialAfter = QuizOfficialScore::query()->count();
        $lessonAfter = (int) DB::table('lesson_completions')->count();
        $topicAfter = (int) DB::table('topic_completions')->count();
        $httpAfter = $this->httpSnapshot();

        $quizPassedDispatched = collect(Event::dispatched(QuizPassed::class))->count();

        return array_merge($report, [
            'status' => 'ok',
            'wrote_to_database' => $appliedAttempts > 0 || $appliedAnswers > 0,
            'database_writes' => $writes,
            'applied_attempts' => $appliedAttempts,
            'applied_answers' => $appliedAnswers,
            'skipped_mapped' => $skippedMapped,
            'deferred_answers' => $deferredAnswers,
            'chunks_committed' => $chunksCommitted,
            'last_successful_chunk' => $report['last_successful_chunk'],
            'last_successful_legacy_key' => $lastSuccessfulLegacyKey,
            'events_dispatched' => $quizPassedDispatched,
            'progress_mutations' => ($lessonAfter - $lessonBefore) + ($topicAfter - $topicBefore),
            'official_score_mutations' => $officialAfter - $officialBefore,
            'emails_sent' => 0,
            'notifications_sent' => 0,
            'external_http' => max(0, $httpAfter - $httpBefore),
            'side_effects' => $quizPassedDispatched > 0
                || ($lessonAfter !== $lessonBefore)
                || ($topicAfter !== $topicBefore)
                || ($officialAfter !== $officialBefore),
            'official_scores_before' => $officialBefore,
            'official_scores_after' => $officialAfter,
            'quiz_passed_dispatched' => $quizPassedDispatched,
        ]);
    }

    /**
     * Test/helper: create a historical attempt without dispatching QuizPassed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function insertHistoricalAttemptSilent(array $payload): QuizAttempt
    {
        Event::fake([QuizPassed::class]);

        return DB::transaction(function () use ($payload) {
            return QuizAttempt::query()->create([
                'quiz_id' => $payload['quiz_id'],
                'user_id' => $payload['user_id'],
                'enrollment_id' => $payload['enrollment_id'] ?? null,
                'attempt_number' => $payload['attempt_number'] ?? 1,
                'status' => $payload['status'] ?? AttemptStatus::Graded,
                'score' => $payload['score'] ?? null,
                'max_score' => $payload['max_score'] ?? null,
                'percentage' => $payload['percentage'] ?? null,
                'passed' => $payload['passed'] ?? null,
                'is_official' => false,
                'started_at' => $payload['started_at'],
                'submitted_at' => $payload['submitted_at'] ?? null,
                'graded_at' => $payload['graded_at'] ?? null,
                'time_spent_seconds' => $payload['time_spent_seconds'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $maps
     * @return array<string, mixed>
     */
    public function hydrateMaps(array $maps): array
    {
        if (($maps['user_maps'] ?? []) === []) {
            $maps['user_maps'] = $this->loadEntityMaps('user');
        }
        if (($maps['course_maps'] ?? []) === []) {
            $maps['course_maps'] = $this->loadEntityMaps('course');
        }
        if (($maps['quiz_maps'] ?? []) === []) {
            $maps['quiz_maps'] = $this->loadEntityMaps('quiz');
        }
        if (($maps['question_maps'] ?? []) === []) {
            $maps['question_maps'] = $this->loadEntityMaps('question');
        }
        if (($maps['enrollments'] ?? []) === []) {
            $maps['enrollments'] = $this->loadEnrollmentIndex($maps['course_maps']);
        }
        $maps['source_orphan_users'] = $maps['source_orphan_users'] ?? ['3'];
        $maps['approved_pending_users'] = $maps['approved_pending_users'] ?? ['5' => 3];

        // If WP5 is already mapped, do not keep it pending.
        if (isset($maps['user_maps']['5'])) {
            unset($maps['approved_pending_users']['5']);
        }

        return $maps;
    }

    /**
     * @return array<string, int>
     */
    private function loadEntityMaps(string $entityType): array
    {
        $out = [];
        LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('entity_type', $entityType)
            ->whereNotNull('local_id')
            ->orderBy('id')
            ->each(function (LegacyImportMap $map) use (&$out): void {
                $out[(string) $map->legacy_id] = (int) $map->local_id;
            });

        return $out;
    }

    /**
     * @param  array<string, int>  $courseMaps
     * @return array<string, int> "localUser|localCourse" => enrollment_id
     */
    private function loadEnrollmentIndex(array $courseMaps): array
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseMaps)));
        if ($courseIds === []) {
            return [];
        }

        $out = [];
        Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('id')
            ->each(function (Enrollment $e) use (&$out): void {
                $key = $e->user_id.'|'.$e->course_id;
                if (! isset($out[$key])) {
                    $out[$key] = (int) $e->id;
                }
            });

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, int>
     */
    private function seedAttemptNumbers(array $candidates): array
    {
        // Start from max existing attempt_number per user+quiz so native attempts stay distinct.
        $seed = [];
        $pairs = [];
        foreach ($candidates as $c) {
            $quizId = $c['local_quiz_id'] ?? null;
            if ($quizId === null) {
                continue;
            }
            $pairs[$c['local_user_id'].'|'.$quizId] = [(int) $c['local_user_id'], (int) $quizId];
        }
        foreach ($pairs as $key => [$uid, $qid]) {
            $max = (int) QuizAttempt::query()
                ->where('user_id', $uid)
                ->where('quiz_id', $qid)
                ->max('attempt_number');
            $seed[$key] = $max;
        }

        return $seed;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{started_at: Carbon, submitted_at: ?Carbon, graded_at: ?Carbon, time_spent_seconds: ?int}
     */
    private function resolveTimestamps(array $candidate): array
    {
        $startedTs = $this->firstPositiveInt([
            $candidate['started'] ?? null,
            $candidate['activity_started'] ?? null,
            $candidate['create_time'] ?? null,
        ]);
        $submittedTs = $this->firstPositiveInt([
            $candidate['completed_meta'] ?? null,
            $candidate['activity_completed'] ?? null,
            $candidate['create_time'] ?? null,
        ]);

        $startedAt = Carbon::createFromTimestamp($startedTs ?? ($submittedTs ?? time()));
        $submittedAt = $submittedTs !== null ? Carbon::createFromTimestamp($submittedTs) : null;
        $timeSpent = null;
        if (isset($candidate['timespent']) && is_numeric($candidate['timespent'])) {
            $timeSpent = (int) round((float) $candidate['timespent']);
        }

        return [
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            'graded_at' => $submittedAt,
            'time_spent_seconds' => $timeSpent,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{
     *     score: ?float,
     *     max_score: ?float,
     *     percentage: ?float,
     *     passed: ?bool,
     *     score_authority: string,
     *     source_score_disagreement: bool
     * }
     */
    private function resolveScores(array $candidate): array
    {
        $score = isset($candidate['points']) && is_numeric($candidate['points'])
            ? (float) $candidate['points']
            : null;
        $max = isset($candidate['total_points']) && is_numeric($candidate['total_points'])
            ? (float) $candidate['total_points']
            : null;
        $pct = isset($candidate['percentage']) && is_numeric($candidate['percentage'])
            ? (float) $candidate['percentage']
            : null;
        $passed = match ($candidate['pass'] ?? 'PASS_UNKNOWN') {
            'PASSED' => true,
            'FAILED' => false,
            default => null,
        };

        $authority = 'user_activity_meta';
        $disagreement = false;

        // STATISTIC_ONLY: no activity meta — fall back to statistic sum only for that class.
        if ($score === null && ! empty($candidate['statistic_ref_id'])
            && ($candidate['correlation'] ?? '') === 'STATISTIC_ONLY') {
            $sum = (float) DB::connection($this->connection->connectionName())
                ->table($this->connection->table('learndash_pro_quiz_statistic'))
                ->where('statistic_ref_id', (int) $candidate['statistic_ref_id'])
                ->sum('points');
            $score = $sum;
            $authority = 'statistic_sum_fallback';
        } elseif ($score !== null && ! empty($candidate['statistic_ref_id'])) {
            $sum = (float) DB::connection($this->connection->connectionName())
                ->table($this->connection->table('learndash_pro_quiz_statistic'))
                ->where('statistic_ref_id', (int) $candidate['statistic_ref_id'])
                ->sum('points');
            if (abs($sum - $score) >= 0.6) {
                $disagreement = true;
            }
        }

        return [
            'score' => $score,
            'max_score' => $max,
            'percentage' => $pct,
            'passed' => $passed,
            'score_authority' => $authority,
            'source_score_disagreement' => $disagreement,
        ];
    }

    /**
     * @param  array<string, int>  $questionMaps
     * @return array{applied: int, deferred: int, writes: int}
     */
    private function persistAnswersForRef(
        int $migrationRunId,
        QuizAttempt $attempt,
        int $statisticRefId,
        array $questionMaps,
    ): array {
        $conn = DB::connection($this->connection->connectionName());
        $rows = $conn->table($this->connection->table('learndash_pro_quiz_statistic').' as s')
            ->leftJoin($this->connection->table('learndash_pro_quiz_question').' as q', 'q.id', '=', 's.question_id')
            ->where('s.statistic_ref_id', $statisticRefId)
            ->orderBy('s.question_id')
            ->get([
                's.statistic_ref_id',
                's.question_id',
                's.answer_data',
                's.points',
                's.correct_count',
                's.incorrect_count',
                'q.answer_type',
            ]);

        $applied = 0;
        $deferred = 0;
        $writes = 0;

        foreach ($rows as $row) {
            $qLegacy = (string) $row->question_id;
            $answerKey = $this->analyzer->answerLegacyKey($statisticRefId, (int) $row->question_id);
            if ($this->maps->find(self::ENTITY_ATTEMPT_ANSWER, $answerKey)?->local_id) {
                continue;
            }

            if (! isset($questionMaps[$qLegacy])) {
                $deferred++;

                continue;
            }

            $question = Question::query()->with('options')->find((int) $questionMaps[$qLegacy]);
            if ($question === null) {
                $deferred++;

                continue;
            }

            $sourceType = (string) ($row->answer_type ?? '');
            if ($sourceType === '') {
                $deferred++;

                continue;
            }

            $normalized = $this->answerNormalizer->normalize($question, $row, $sourceType);
            if (! ($normalized['ok'] ?? false)) {
                $deferred++;

                continue;
            }

            $payload = $normalized['payload'];
            $answer = QuizAttemptAnswer::query()->create([
                'quiz_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_ids' => $payload['selected_option_ids'],
                'text_answer' => $payload['text_answer'],
                'numeric_answer' => $payload['numeric_answer'],
                'matching_answer' => $payload['matching_answer'],
                'ordering_answer' => $payload['ordering_answer'],
                'interactive_answer' => $payload['interactive_answer'],
                'client_result' => $payload['client_result'],
                'server_result' => $payload['server_result'],
                'needs_manual_review' => (bool) ($payload['needs_manual_review'] ?? false),
                'is_correct' => $payload['is_correct'],
                'points_awarded' => $payload['points_awarded'],
            ]);
            $writes++;
            $applied++;

            $this->maps->upsertMapping(self::ENTITY_ATTEMPT_ANSWER, $answerKey, [
                'migration_run_id' => $migrationRunId,
                'local_id' => $answer->id,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipCreated([
                    'historical_import' => true,
                    'statistic_ref_id' => $statisticRefId,
                    'legacy_question_id' => $qLegacy,
                    'source_answer_type' => $sourceType,
                    'attempt_local_id' => $attempt->id,
                ]),
            ]);
            $writes++;
        }

        return ['applied' => $applied, 'deferred' => $deferred, 'writes' => $writes];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstPositiveInt(array $values): ?int
    {
        foreach ($values as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_numeric($v) && (int) $v > 0) {
                return (int) $v;
            }
        }

        return null;
    }

    private function httpSnapshot(): int
    {
        try {
            return count(Http::recorded());
        } catch (Throwable) {
            return 0;
        }
    }
}
