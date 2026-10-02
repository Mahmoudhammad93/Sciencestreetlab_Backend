<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Support\Facades\DB;

/**
 * Read-only LearnDash historical quiz attempt reconstruction.
 *
 * Correlation authority:
 * 1) user_activity_meta.statistic_ref_id → statistic_ref (EXACT_MATCH)
 * 2) user+quiz_post+create_time=activity_completed unique (HIGH_CONFIDENCE_MATCH)
 * 3) STATISTIC_ONLY / ACTIVITY_ONLY remainder (never double-count)
 *
 * Does not write Laravel or WordPress rows.
 */
final class WordPressQuizAttemptHistoryAnalyzer
{
    public const ENTITY_ATTEMPT = 'quiz_attempt';

    public const ENTITY_ATTEMPT_ANSWER = 'quiz_attempt_answer';

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly WordPressQuizLessonMappingAnalyzer $quizMapping,
    ) {}

    /**
     * @param  array{
     *   user_maps?: array<string,int>,
     *   course_maps?: array<string,int>,
     *   enrollments?: array<string,int>,
     *   approved_pending_users?: array<string,int>,
     *   source_orphan_users?: list<string>
     * }  $maps
     * @return array<string, mixed>
     */
    public function analyze(array $maps = []): array
    {
        $ready = $this->connection->assertReadyForImport('posts');
        if (! ($ready['ok'] ?? false)) {
            return [
                'status' => 'blocked',
                'code' => 'WORDPRESS_NOT_READY',
                'wrote_to_database' => false,
                'database_writes' => 0,
            ];
        }

        $quizMap = $this->quizMapping->analyze();
        if (($quizMap['status'] ?? '') !== 'ok') {
            return [
                'status' => 'blocked',
                'code' => 'QUIZ_MAPPING_NOT_READY',
                'wrote_to_database' => false,
                'database_writes' => 0,
                'quiz_mapping' => $quizMap,
            ];
        }

        $supportedQuizzes = [];
        foreach ($quizMap['supported_relationships'] as $row) {
            $supportedQuizzes[(int) $row['quiz_legacy_id']] = $row;
        }
        $emptyDeferred = [];
        foreach ($quizMap['deferred_relationships'] as $row) {
            if (($row['import_decision'] ?? '') === 'DEFERRED_EMPTY_QUIZ') {
                $emptyDeferred[(int) $row['quiz_legacy_id']] = true;
            }
        }

        $userMaps = $maps['user_maps'] ?? [];
        $courseMaps = $maps['course_maps'] ?? [];
        $enrollments = $maps['enrollments'] ?? []; // "localUser|localCourse" => enrollment_id
        $pendingUsers = $maps['approved_pending_users'] ?? ['5' => 3];
        $orphanUsers = array_fill_keys($maps['source_orphan_users'] ?? ['3'], true);

        $conn = $this->connection->connectionName();
        $c = DB::connection($conn);

        $meta = $this->loadQuizActivityMeta($c);
        $refs = $c->table($this->connection->table('learndash_pro_quiz_statistic_ref'))
            ->get()
            ->keyBy(fn ($r) => (int) $r->statistic_ref_id);
        $acts = $c->table($this->connection->table('learndash_user_activity'))
            ->where('activity_type', 'quiz')
            ->get()
            ->keyBy(fn ($a) => (int) $a->activity_id);

        $correlation = $this->correlate($refs, $acts, $meta);
        $attempts = $this->buildAttempts($correlation, $refs, $acts, $meta);

        $matrix = $this->emptyMatrix();
        $matrix['STATISTIC_REFS_TOTAL'] = $refs->count();
        $matrix['QUIZ_USER_ACTIVITIES_TOTAL'] = $acts->count();
        $matrix['RECONSTRUCTED_UNIQUE_ATTEMPTS'] = count($attempts);
        $matrix['EXACT_MATCHED'] = count($correlation['exact']);
        $matrix['HIGH_CONFIDENCE_MATCHED'] = count($correlation['high']);
        $matrix['STATISTIC_ONLY'] = count($correlation['stat_only']);
        $matrix['ACTIVITY_ONLY'] = count($correlation['act_only']);
        $matrix['AMBIGUOUS'] = count($correlation['ambiguous']);
        $matrix['DUPLICATE_SOURCE_ROWS'] = 0;

        $qstatTotal = (int) $c->table($this->connection->table('learndash_pro_quiz_statistic'))->count();
        $matrix['QUESTION_STAT_ROWS'] = $qstatTotal;
        $matrix['ANSWER_HISTORY_RECOVERABLE'] = (int) $c->table($this->connection->table('learndash_pro_quiz_statistic'))
            ->whereNotNull('answer_data')->where('answer_data', '!=', '')->count();
        $matrix['ANSWER_HISTORY_PARTIAL'] = 0;
        $matrix['ANSWER_HISTORY_UNRECOVERABLE'] = max(0, $qstatTotal - $matrix['ANSWER_HISTORY_RECOVERABLE']);

        $essay = $c->selectOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN s.correct_count > 0 OR s.incorrect_count > 0 OR s.points > 0 THEN 1 ELSE 0 END) AS graded
             FROM '.$this->connection->physicalTable('learndash_pro_quiz_statistic').' s
             INNER JOIN '.$this->connection->physicalTable('learndash_pro_quiz_question').' q ON q.id = s.question_id
             WHERE q.answer_type = ?',
            ['essay']
        );
        $matrix['ESSAY_ATTEMPTS'] = (int) ($essay->total ?? 0);
        $matrix['ESSAY_GRADED'] = (int) ($essay->graded ?? 0);
        $matrix['ESSAY_UNGRADED'] = max(0, $matrix['ESSAY_ATTEMPTS'] - $matrix['ESSAY_GRADED']);

        $byUserQuiz = [];
        $c8507Users = [];
        $wouldRefs = [];
        $deferredRefs = [];
        $dry = [
            'WOULD_CREATE_ATTEMPTS' => 0,
            'WOULD_CREATE_ATTEMPT_ANSWERS' => 0,
            'WOULD_MAP_EXISTING' => 0,
            'DEFERRED_USER' => 0,
            'DEFERRED_USER_5' => 0,
            'DEFERRED_USER_PENDING' => 0,
            'DEFERRED_SOURCE_ORPHAN_USER' => 0,
            'DEFERRED_OTHER_USER' => 0,
            'DEFERRED_MISSING_ENROLLMENT' => 0,
            'DEFERRED_MISSING_QUIZ_MAP' => 0,
            'DEFERRED_MISSING_QUESTION_MAP' => 0,
            'DEFERRED_QUIZ' => 0,
            'DEFERRED_AMBIGUOUS' => 0,
            'DEFERRED_ATTEMPT_ANSWERS' => 0,
            'UNRECOVERABLE_ATTEMPT_ANSWERS' => 0,
            'UNSUPPORTED_ANSWERS' => 0,
            'SKIPPED_OUTSIDE_SCOPE' => 0,
            'SKIPPED_UNRECOVERABLE' => 0,
            'WITH_ENROLLMENT' => 0,
            'REQUIRES_NULL_ENROLLMENT' => 0,
            'IMPORTABLE_EXACT' => 0,
            'IMPORTABLE_HIGH' => 0,
            'IMPORTABLE_STAT_ONLY' => 0,
            'IMPORTABLE_ACTIVITY_ONLY' => 0,
            'WP5_WOULD_IMPORT' => 0,
            'WP27_WOULD_IMPORT' => 0,
            'WP1641_WOULD_IMPORT' => 0,
            'WP1642_WOULD_IMPORT' => 0,
            'CONFLICTS' => 0,
            'DUPLICATE_SOURCE_KEYS' => 0,
            'ALLOW_NULL_ENROLLMENT_FOR_HISTORICAL' => true,
        ];
        $perCourse = [];
        $course8507 = ['attempts' => 0, 'users' => [], 'passed' => 0, 'failed' => 0, 'unknown' => 0, 'earliest' => null, 'latest' => null];
        $lesson8879 = ['attempts' => 0, 'users' => [], 'passed' => 0, 'failed' => 0, 'percentages' => []];
        $candidates = [];
        $seenLegacyKeys = [];
        $quizMaps = $maps['quiz_maps'] ?? [];
        $questionMaps = $maps['question_maps'] ?? [];

        foreach ($attempts as $a) {
            $quizClass = $this->classifyQuiz((int) $a['quiz_post'], $supportedQuizzes, $emptyDeferred);
            $userClass = $this->classifyUser((string) $a['user_id'], $userMaps, $pendingUsers, $orphanUsers);
            $pass = $a['pass'];
            $completed = (bool) $a['completed'];

            if ($quizClass === 'SUPPORTED_IMPORT_QUIZ') {
                $matrix['ATTEMPTS_IN_209_IMPORTABLE_QUIZZES']++;
            } elseif ($quizClass === 'EMPTY_DEFERRED_QUIZ') {
                $matrix['ATTEMPTS_EMPTY_DEFERRED_QUIZZES']++;
                $matrix['ATTEMPTS_OUTSIDE_ACTIVE_QUIZ_SCOPE']++;
            } elseif ($quizClass === 'UNKNOWN_MASTER' || $a['quiz_post'] <= 0) {
                $matrix['ATTEMPTS_UNKNOWN_QUIZ']++;
                $matrix['ATTEMPTS_OUTSIDE_ACTIVE_QUIZ_SCOPE']++;
            } else {
                $matrix['ATTEMPTS_OUTSIDE_ACTIVE_QUIZ_SCOPE']++;
            }

            match ($userClass) {
                'USER_MAPPED' => $matrix['ATTEMPTS_USER_MAPPED']++,
                'USER_MAPPING_PENDING' => $matrix['ATTEMPTS_USER_MAPPING_PENDING']++,
                'SOURCE_ORPHAN_USER' => $matrix['ATTEMPTS_SOURCE_ORPHAN_USER']++,
                default => $matrix['ATTEMPTS_OTHER_UNMAPPED_USER']++,
            };

            if ($completed) {
                $matrix['COMPLETED']++;
            }
            match ($pass) {
                'PASSED' => $matrix['PASSED']++,
                'FAILED' => $matrix['FAILED']++,
                default => $matrix['PASS_UNKNOWN']++,
            };

            $uq = $a['user_id'].':'.$a['quiz_post'];
            $byUserQuiz[$uq] = ($byUserQuiz[$uq] ?? 0) + 1;

            $cid = (int) $a['course_post'];
            $perCourse[$cid] = $perCourse[$cid] ?? [
                'course_legacy_id' => $cid,
                'attempts' => 0,
                'would_create' => 0,
                'deferred_user' => 0,
                'deferred_enrollment' => 0,
                'skipped_outside' => 0,
            ];
            $perCourse[$cid]['attempts']++;

            if ($cid === 8507) {
                $course8507['attempts']++;
                $course8507['users'][$a['user_id']] = true;
                if ($pass === 'PASSED') {
                    $course8507['passed']++;
                } elseif ($pass === 'FAILED') {
                    $course8507['failed']++;
                } else {
                    $course8507['unknown']++;
                }
                $ts = (int) ($a['create_time'] ?? 0);
                if ($ts > 0) {
                    $course8507['earliest'] = $course8507['earliest'] === null ? $ts : min($course8507['earliest'], $ts);
                    $course8507['latest'] = $course8507['latest'] === null ? $ts : max($course8507['latest'], $ts);
                }
            }

            if ((int) $a['quiz_post'] === 8324) {
                $lesson8879['attempts']++;
                $lesson8879['users'][$a['user_id']] = true;
                if ($pass === 'PASSED') {
                    $lesson8879['passed']++;
                }
                if ($pass === 'FAILED') {
                    $lesson8879['failed']++;
                }
                if ($a['percentage'] !== null) {
                    $lesson8879['percentages'][] = (float) $a['percentage'];
                }
            }

            // Dry-run decision (approved: ALLOW_NULL_ENROLLMENT_FOR_HISTORICAL_QUIZ_ATTEMPTS)
            if ($quizClass !== 'SUPPORTED_IMPORT_QUIZ') {
                if ($quizClass === 'EMPTY_DEFERRED_QUIZ') {
                    $dry['DEFERRED_QUIZ']++;
                } else {
                    $dry['SKIPPED_OUTSIDE_SCOPE']++;
                }
                $perCourse[$cid]['skipped_outside']++;

                continue;
            }

            if ($userClass === 'USER_MAPPING_PENDING') {
                $dry['DEFERRED_USER_PENDING']++;
                $dry['DEFERRED_USER_5']++;
                $dry['DEFERRED_USER']++;
                $perCourse[$cid]['deferred_user']++;
                if ($a['statistic_ref_id']) {
                    $deferredRefs[] = (int) $a['statistic_ref_id'];
                }

                continue;
            }
            if ($userClass === 'SOURCE_ORPHAN_USER') {
                $dry['DEFERRED_SOURCE_ORPHAN_USER']++;
                $dry['DEFERRED_USER']++;
                $perCourse[$cid]['deferred_user']++;
                if ($a['statistic_ref_id']) {
                    $deferredRefs[] = (int) $a['statistic_ref_id'];
                }

                continue;
            }
            if ($userClass !== 'USER_MAPPED') {
                $dry['DEFERRED_OTHER_USER']++;
                $dry['DEFERRED_USER']++;
                $perCourse[$cid]['deferred_user']++;
                if ($a['statistic_ref_id']) {
                    $deferredRefs[] = (int) $a['statistic_ref_id'];
                }

                continue;
            }

            $legacyKey = $this->attemptLegacyKey($a);
            if (isset($seenLegacyKeys[$legacyKey])) {
                $dry['DUPLICATE_SOURCE_KEYS']++;
                $matrix['DUPLICATE_SOURCE_ROWS']++;

                continue;
            }
            $seenLegacyKeys[$legacyKey] = true;

            $quizLegacy = (string) $a['quiz_post'];
            if ($quizMaps !== [] && ! isset($quizMaps[$quizLegacy])) {
                $dry['DEFERRED_MISSING_QUIZ_MAP']++;
                if ($a['statistic_ref_id']) {
                    $deferredRefs[] = (int) $a['statistic_ref_id'];
                }

                continue;
            }

            $localUserId = (int) $userMaps[(string) $a['user_id']];
            $localCourseId = isset($courseMaps[(string) $cid]) ? (int) $courseMaps[(string) $cid] : null;
            $enrollmentId = null;
            if ($localCourseId !== null) {
                $enrollmentId = $enrollments[$localUserId.'|'.$localCourseId] ?? null;
            }

            // Mapped user + supported quiz → eligible even without enrollment.
            $dry['WOULD_CREATE_ATTEMPTS']++;
            $perCourse[$cid]['would_create']++;
            if ($enrollmentId !== null) {
                $dry['WITH_ENROLLMENT']++;
            } else {
                $dry['REQUIRES_NULL_ENROLLMENT']++;
            }
            match ((string) $a['correlation']) {
                'EXACT_MATCH' => $dry['IMPORTABLE_EXACT']++,
                'HIGH_CONFIDENCE_MATCH' => $dry['IMPORTABLE_HIGH']++,
                'STATISTIC_ONLY' => $dry['IMPORTABLE_STAT_ONLY']++,
                'ACTIVITY_ONLY' => $dry['IMPORTABLE_ACTIVITY_ONLY']++,
                default => null,
            };
            match ((string) $a['user_id']) {
                '5' => $dry['WP5_WOULD_IMPORT']++,
                '27' => $dry['WP27_WOULD_IMPORT']++,
                '1641' => $dry['WP1641_WOULD_IMPORT']++,
                '1642' => $dry['WP1642_WOULD_IMPORT']++,
                default => null,
            };
            if ($a['statistic_ref_id']) {
                $wouldRefs[] = (int) $a['statistic_ref_id'];
            }

            if (($maps['include_candidates'] ?? false) === true) {
                $candidates[] = [
                    'decision' => 'IMPORTABLE',
                    'legacy_key' => $legacyKey,
                    'correlation' => $a['correlation'],
                    'statistic_ref_id' => $a['statistic_ref_id'],
                    'activity_id' => $a['activity_id'],
                    'legacy_user_id' => (string) $a['user_id'],
                    'local_user_id' => $localUserId,
                    'legacy_quiz_id' => (int) $a['quiz_post'],
                    'local_quiz_id' => $quizMaps[$quizLegacy] ?? null,
                    'legacy_course_id' => $cid,
                    'local_course_id' => $localCourseId,
                    'enrollment_id' => $enrollmentId,
                    'create_time' => (int) ($a['create_time'] ?? 0),
                    'pass' => $a['pass'],
                    'completed' => $a['completed'],
                    'percentage' => $a['percentage'],
                    'points' => $a['points'],
                    'total_points' => $a['total_points'],
                    'started' => $a['started'] ?? null,
                    'completed_meta' => $a['completed_meta'] ?? null,
                    'timespent' => $a['timespent'] ?? null,
                    'activity_started' => $a['activity_started'] ?? null,
                    'activity_completed' => $a['activity_completed'] ?? null,
                    'pass_authority' => $a['pass_authority'],
                    'master_id' => $a['master_id'],
                ];
            }
            // ACTIVITY_ONLY: no statistic answer rows → nothing to import as answers
        }

        $dry['DEFERRED_AMBIGUOUS'] = $matrix['AMBIGUOUS'];

        foreach ($byUserQuiz as $n) {
            if ($n > 1) {
                $matrix['MULTIPLE_ATTEMPT_USERS']++;
                $matrix['RETRY_ATTEMPTS'] += ($n - 1);
            }
        }

        $statTable = $this->connection->table('learndash_pro_quiz_statistic');
        if ($wouldRefs !== []) {
            foreach (array_chunk(array_values(array_unique($wouldRefs)), 500) as $chunk) {
                $dry['WOULD_CREATE_ATTEMPT_ANSWERS'] += (int) $c->table($statTable)
                    ->whereIn('statistic_ref_id', $chunk)
                    ->whereNotNull('answer_data')
                    ->where('answer_data', '!=', '')
                    ->count();
                $dry['UNRECOVERABLE_ATTEMPT_ANSWERS'] += (int) $c->table($statTable)
                    ->whereIn('statistic_ref_id', $chunk)
                    ->where(function ($q): void {
                        $q->whereNull('answer_data')->orWhere('answer_data', '=', '');
                    })
                    ->count();
            }
        }
        if ($deferredRefs !== []) {
            foreach (array_chunk(array_values(array_unique($deferredRefs)), 500) as $chunk) {
                $dry['DEFERRED_ATTEMPT_ANSWERS'] += (int) $c->table($statTable)
                    ->whereIn('statistic_ref_id', $chunk)
                    ->whereNotNull('answer_data')
                    ->where('answer_data', '!=', '')
                    ->count();
            }
        }
        $dry['SOURCE_QUESTION_STAT_ROWS'] = $qstatTotal;

        $matrix['COURSE_8507_ATTEMPTS'] = $course8507['attempts'];
        $matrix['COURSE_8507_UNIQUE_USERS'] = count($course8507['users']);
        $matrix['LESSON_8879_QUIZ_8324_ATTEMPTS'] = $lesson8879['attempts'];

        $scoreSemantics = $this->scoreSemanticsSample($correlation['exact'], $refs, $meta, $c);

        $result = [
            'status' => 'ok',
            'dry_run' => true,
            'wrote_to_database' => false,
            'database_writes' => 0,
            'business_decision' => 'PRESERVE_ALL_RELIABLY_ATTRIBUTABLE_HISTORY',
            'correlation_authority' => [
                'primary' => 'user_activity_meta.statistic_ref_id → learndash_pro_quiz_statistic_ref.statistic_ref_id',
                'secondary' => 'user_id + quiz_post_id + create_time = activity_completed (unique only)',
                'attempt_identity' => 'statistic_ref_id when present; else activity:{activity_id}',
                'no_double_count' => true,
            ],
            'schema_compatibility' => [
                'enrollment_id_nullable_for_historical' => true,
                'live_enrollment_still_required' => true,
                'started_at_required' => true,
                'historical_timestamps_supported' => true,
                'policy' => 'ALLOW_NULL_ENROLLMENT_FOR_HISTORICAL_QUIZ_ATTEMPTS',
                'note' => 'Historical importer may persist enrollment_id=null; live QuizAttemptService/API still requires Enrollment.',
            ],
            'approved_decisions' => [
                'user_5' => 'RESTORE_AND_MAP_EXISTING (not executed in this phase)',
                'missing_enrollment' => 'ALLOW_NULL_ENROLLMENT_FOR_HISTORICAL_QUIZ_ATTEMPTS',
                'score_authority' => 'USER_ACTIVITY_META_IS_HISTORICAL_RESULT_AUTHORITY',
                'outside_tree' => 'LEGACY_HISTORY_OUTSIDE_ACTIVE_SCOPE',
            ],
            'score_authority' => 'user_activity_meta',
            'user_5_dependency' => [
                'policy' => 'RESTORE_AND_MAP_EXISTING',
                'legacy_id' => '5',
                'local_user_id' => 3,
                'real_restore_executed' => false,
                'must_run_before_attempt_import' => true,
                'config' => 'wordpress.approved_user_resolutions',
            ],
            'side_effect_isolation' => [
                'must_not_use' => 'QuizAttemptService::submit / ManualQuizReviewService::finalizeIfReady',
                'reason' => 'fires QuizPassed → gamification listeners; may touch progress/official scores',
                'historical_path' => 'direct Eloquent create inside Event::fake()/withoutEvents + historical_import metadata',
            ],
            'matrix' => $matrix,
            'dry_run_totals' => $dry,
            'per_course' => array_values($perCourse),
            'course_8507' => [
                'attempts' => $course8507['attempts'],
                'unique_users' => count($course8507['users']),
                'passed' => $course8507['passed'],
                'failed' => $course8507['failed'],
                'pass_unknown' => $course8507['unknown'],
                'earliest_ts' => $course8507['earliest'],
                'latest_ts' => $course8507['latest'],
                'question_stat_rows_note' => 'included in global QUESTION_STAT_ROWS; per-course breakdown via statistic_ref.course_post_id',
            ],
            'lesson_8879_quiz_8324' => [
                'lesson_legacy_id' => 8879,
                'quiz_legacy_id' => 8324,
                'pro_quiz_master_id' => 119,
                'attempts' => $lesson8879['attempts'],
                'unique_users' => count($lesson8879['users']),
                'passed' => $lesson8879['passed'],
                'failed' => $lesson8879['failed'],
                'percentage_min' => $lesson8879['percentages'] !== [] ? min($lesson8879['percentages']) : null,
                'percentage_max' => $lesson8879['percentages'] !== [] ? max($lesson8879['percentages']) : null,
                'answer_history' => 'RECOVERABLE via statistic rows for matched refs',
            ],
            'score_semantics' => $scoreSemantics,
            'quiz_definition_dependency' => [
                'supported_quizzes' => count($supportedQuizzes),
                'requires_quiz_definition_import_first' => true,
            ],
            'maps_provided' => [
                'user_maps' => count($userMaps),
                'course_maps' => count($courseMaps),
                'enrollments' => count($enrollments),
                'quiz_maps' => count($quizMaps),
                'question_maps' => count($questionMaps),
            ],
            'inspect' => $ready['inspect'] ?? null,
        ];

        if (($maps['include_candidates'] ?? false) === true) {
            usort($candidates, static function (array $a, array $b): int {
                return [$a['create_time'], $a['statistic_ref_id'] ?? 0, $a['activity_id'] ?? 0]
                    <=> [$b['create_time'], $b['statistic_ref_id'] ?? 0, $b['activity_id'] ?? 0];
            });
            $result['candidates'] = $candidates;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    public function attemptLegacyKey(array $attempt): string
    {
        if (! empty($attempt['statistic_ref_id'])) {
            return 'statistic_ref:'.(int) $attempt['statistic_ref_id'];
        }

        return 'activity:'.(int) ($attempt['activity_id'] ?? 0);
    }

    public function answerLegacyKey(int $statisticRefId, int $questionId): string
    {
        return 'statistic:'.$statisticRefId.':'.$questionId;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function loadQuizActivityMeta(mixed $c): array
    {
        $keys = [
            'statistic_ref_id', 'percentage', 'pass', 'points', 'total_points', 'score', 'count',
            'pro_quizid', 'lesson', 'course', 'started', 'completed', 'has_graded', 'timespent',
        ];
        $rows = $c->table($this->connection->table('learndash_user_activity_meta').' as m')
            ->join($this->connection->table('learndash_user_activity').' as a', 'a.activity_id', '=', 'm.activity_id')
            ->where('a.activity_type', 'quiz')
            ->whereIn('m.activity_meta_key', $keys)
            ->get(['m.activity_id', 'm.activity_meta_key', 'm.activity_meta_value']);

        $meta = [];
        foreach ($rows as $row) {
            $meta[(int) $row->activity_id][(string) $row->activity_meta_key] = (string) $row->activity_meta_value;
        }

        return $meta;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $refs
     * @param  \Illuminate\Support\Collection<int, object>  $acts
     * @param  array<int, array<string, string>>  $meta
     * @return array{exact: array<int,int>, high: array<int,int>, stat_only: list<int>, act_only: list<int>, ambiguous: array<int, list<int>>}
     */
    private function correlate($refs, $acts, array $meta): array
    {
        $refToActs = [];
        foreach ($meta as $aid => $m) {
            if (! isset($m['statistic_ref_id']) || $m['statistic_ref_id'] === '') {
                continue;
            }
            $rid = (int) $m['statistic_ref_id'];
            if ($refs->has($rid)) {
                $refToActs[$rid][] = $aid;
            }
        }

        $exact = [];
        $ambiguous = [];
        foreach ($refToActs as $rid => $aids) {
            $uniq = array_values(array_unique($aids));
            if (count($uniq) === 1) {
                $exact[$rid] = $uniq[0];
            } else {
                $ambiguous[$rid] = $uniq;
            }
        }

        $usedActs = array_fill_keys(array_values($exact), true);
        foreach ($ambiguous as $aids) {
            foreach ($aids as $aid) {
                $usedActs[$aid] = true;
            }
        }

        $high = [];
        foreach ($refs as $rid => $r) {
            if (isset($exact[$rid]) || isset($ambiguous[$rid])) {
                continue;
            }
            $cands = [];
            foreach ($acts as $aid => $a) {
                if (isset($usedActs[$aid])) {
                    continue;
                }
                if (
                    (int) $a->user_id === (int) $r->user_id
                    && (int) $a->post_id === (int) $r->quiz_post_id
                    && (int) $a->activity_completed === (int) $r->create_time
                ) {
                    $cands[] = $aid;
                }
            }
            if (count($cands) === 1) {
                $high[$rid] = $cands[0];
                $usedActs[$cands[0]] = true;
            } elseif (count($cands) > 1) {
                $ambiguous[$rid] = $cands;
                foreach ($cands as $aid) {
                    $usedActs[$aid] = true;
                }
            }
        }

        $statOnly = [];
        foreach ($refs as $rid => $_) {
            if (! isset($exact[$rid]) && ! isset($high[$rid]) && ! isset($ambiguous[$rid])) {
                $statOnly[] = $rid;
            }
        }

        $actOnly = [];
        foreach ($acts as $aid => $_) {
            if (! isset($usedActs[$aid])) {
                $actOnly[] = $aid;
            }
        }

        return [
            'exact' => $exact,
            'high' => $high,
            'stat_only' => $statOnly,
            'act_only' => $actOnly,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @param  array{exact: array<int,int>, high: array<int,int>, stat_only: list<int>, act_only: list<int>, ambiguous: array<int, list<int>>}  $correlation
     * @param  \Illuminate\Support\Collection<int, object>  $refs
     * @param  \Illuminate\Support\Collection<int, object>  $acts
     * @param  array<int, array<string, string>>  $meta
     * @return list<array<string, mixed>>
     */
    private function buildAttempts(array $correlation, $refs, $acts, array $meta): array
    {
        $out = [];
        $push = function (string $corr, ?int $ref, ?int $aid, object|array $src) use (&$out, $meta, $acts): void {
            $m = $aid ? ($meta[$aid] ?? []) : [];
            $pass = 'PASS_UNKNOWN';
            if (array_key_exists('pass', $m)) {
                $pass = (string) $m['pass'] === '1' ? 'PASSED' : ((string) $m['pass'] === '0' ? 'FAILED' : 'PASS_UNKNOWN');
            }
            $completed = false;
            if ($aid) {
                $completed = ! empty($acts[$aid]->activity_completed) || ! empty($m['completed']);
            } elseif ($ref) {
                $completed = true;
            }

            if (is_object($src)) {
                $userId = (int) $src->user_id;
                $quizPost = (int) ($src->quiz_post_id ?? $src->post_id ?? 0);
                $coursePost = (int) ($src->course_post_id ?? $src->course_id ?? 0);
                $master = isset($src->quiz_id) ? (int) $src->quiz_id : (isset($m['pro_quizid']) ? (int) $m['pro_quizid'] : null);
                $ctime = (int) ($src->create_time ?? $src->activity_completed ?? 0);
            } else {
                $userId = (int) $src['user_id'];
                $quizPost = (int) $src['quiz_post'];
                $coursePost = (int) $src['course_post'];
                $master = $src['master'];
                $ctime = (int) $src['create_time'];
            }

            $out[] = [
                'correlation' => $corr,
                'statistic_ref_id' => $ref,
                'activity_id' => $aid,
                'user_id' => $userId,
                'quiz_post' => $quizPost,
                'course_post' => $coursePost,
                'master_id' => $master,
                'create_time' => $ctime,
                'pass' => $pass,
                'completed' => $completed,
                'percentage' => $m['percentage'] ?? null,
                'points' => $m['points'] ?? null,
                'total_points' => $m['total_points'] ?? null,
                'started' => $m['started'] ?? null,
                'completed_meta' => $m['completed'] ?? null,
                'timespent' => $m['timespent'] ?? null,
                'activity_started' => $aid ? ($acts[$aid]->activity_started ?? null) : null,
                'activity_completed' => $aid ? ($acts[$aid]->activity_completed ?? null) : null,
                'pass_authority' => array_key_exists('pass', $m) ? 'SOURCE_STORED' : 'UNKNOWN',
            ];
        };

        foreach ($correlation['exact'] as $rid => $aid) {
            $push('EXACT_MATCH', $rid, $aid, $refs[$rid]);
        }
        foreach ($correlation['high'] as $rid => $aid) {
            $push('HIGH_CONFIDENCE_MATCH', $rid, $aid, $refs[$rid]);
        }
        foreach ($correlation['stat_only'] as $rid) {
            $push('STATISTIC_ONLY', $rid, null, $refs[$rid]);
        }
        foreach ($correlation['act_only'] as $aid) {
            $a = $acts[$aid];
            $m = $meta[$aid] ?? [];
            $push('ACTIVITY_ONLY', null, $aid, [
                'user_id' => (int) $a->user_id,
                'quiz_post' => (int) $a->post_id,
                'course_post' => (int) $a->course_id,
                'master' => isset($m['pro_quizid']) ? (int) $m['pro_quizid'] : null,
                'create_time' => (int) ($a->activity_completed ?: 0),
            ]);
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $exact
     * @param  \Illuminate\Support\Collection<int, object>  $refs
     * @param  array<int, array<string, string>>  $meta
     * @return array<string, mixed>
     */
    private function scoreSemanticsSample(array $exact, $refs, array $meta, mixed $c): array
    {
        $exactMatch = 0;
        $rounding = 0;
        $disagree = 0;
        $missing = 0;
        $checked = 0;

        foreach ($exact as $rid => $aid) {
            $m = $meta[$aid] ?? [];
            if (! isset($m['points'])) {
                $missing++;

                continue;
            }
            $checked++;
            $sum = (float) $c->table($this->connection->table('learndash_pro_quiz_statistic'))
                ->where('statistic_ref_id', $rid)
                ->sum('points');
            $act = (float) $m['points'];
            $delta = abs($sum - $act);
            if ($delta < 0.001) {
                $exactMatch++;
            } elseif ($delta < 0.6) {
                $rounding++;
            } else {
                $disagree++;
            }
        }

        return [
            'authority_when_linked' => 'USER_ACTIVITY_META_IS_HISTORICAL_RESULT_AUTHORITY',
            'score_authority' => 'user_activity_meta',
            'statistic_rows_role' => 'per-question answers/points/correctness/essay evidence only',
            'disagreement_policy' => 'Do not replace activity-meta final score/pass with statistic sums; record source_score_disagreement=true in future persist metadata',
            'statistic_sum_comparison_on_exact_matches' => [
                'checked' => $checked,
                'EXACT_SCORE_MATCH' => $exactMatch,
                'ROUNDING_DIFFERENCE' => $rounding,
                'SOURCE_DISAGREEMENT' => $disagree,
                'MISSING_SCORE' => $missing,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $supported
     * @param  array<int, bool>  $emptyDeferred
     */
    private function classifyQuiz(int $quizPost, array $supported, array $emptyDeferred): string
    {
        if ($quizPost <= 0) {
            return 'UNKNOWN_MASTER';
        }
        if (isset($supported[$quizPost])) {
            return 'SUPPORTED_IMPORT_QUIZ';
        }
        if (isset($emptyDeferred[$quizPost])) {
            return 'EMPTY_DEFERRED_QUIZ';
        }

        return 'OUTSIDE_TREE_QUIZ';
    }

    /**
     * @param  array<string, int>  $userMaps
     * @param  array<string, int>  $pending
     * @param  array<string, bool>  $orphans
     */
    private function classifyUser(string $legacyUserId, array $userMaps, array $pending, array $orphans): string
    {
        if ($legacyUserId === '' || (int) $legacyUserId <= 0) {
            return 'NO_USER';
        }
        if (isset($userMaps[$legacyUserId])) {
            return 'USER_MAPPED';
        }
        if (isset($pending[$legacyUserId])) {
            return 'USER_MAPPING_PENDING';
        }
        if (isset($orphans[$legacyUserId])) {
            return 'SOURCE_ORPHAN_USER';
        }

        return 'USER_NOT_MAPPED';
    }

    /**
     * @return array<string, int>
     */
    private function emptyMatrix(): array
    {
        return [
            'STATISTIC_REFS_TOTAL' => 0,
            'QUIZ_USER_ACTIVITIES_TOTAL' => 0,
            'RECONSTRUCTED_UNIQUE_ATTEMPTS' => 0,
            'EXACT_MATCHED' => 0,
            'HIGH_CONFIDENCE_MATCHED' => 0,
            'STATISTIC_ONLY' => 0,
            'ACTIVITY_ONLY' => 0,
            'AMBIGUOUS' => 0,
            'DUPLICATE_SOURCE_ROWS' => 0,
            'ATTEMPTS_IN_209_IMPORTABLE_QUIZZES' => 0,
            'ATTEMPTS_OUTSIDE_ACTIVE_QUIZ_SCOPE' => 0,
            'ATTEMPTS_EMPTY_DEFERRED_QUIZZES' => 0,
            'ATTEMPTS_UNKNOWN_QUIZ' => 0,
            'ATTEMPTS_USER_MAPPED' => 0,
            'ATTEMPTS_USER_MAPPING_PENDING' => 0,
            'ATTEMPTS_SOURCE_ORPHAN_USER' => 0,
            'ATTEMPTS_OTHER_UNMAPPED_USER' => 0,
            'COMPLETED' => 0,
            'PASSED' => 0,
            'FAILED' => 0,
            'PASS_UNKNOWN' => 0,
            'MULTIPLE_ATTEMPT_USERS' => 0,
            'RETRY_ATTEMPTS' => 0,
            'QUESTION_STAT_ROWS' => 0,
            'ANSWER_HISTORY_RECOVERABLE' => 0,
            'ANSWER_HISTORY_PARTIAL' => 0,
            'ANSWER_HISTORY_UNRECOVERABLE' => 0,
            'ESSAY_ATTEMPTS' => 0,
            'ESSAY_GRADED' => 0,
            'ESSAY_UNGRADED' => 0,
            'COURSE_8507_ATTEMPTS' => 0,
            'COURSE_8507_UNIQUE_USERS' => 0,
            'LESSON_8879_QUIZ_8324_ATTEMPTS' => 0,
        ];
    }
}
