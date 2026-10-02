<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Support\Facades\DB;

/**
 * Read-only LearnDash quiz ↔ lesson relationship reconstruction.
 *
 * Authority: ld_course_steps (APPROVED). Never invents lesson↔quiz links.
 * Does not import, mutate WordPress, or write Laravel rows.
 */
final class WordPressQuizLessonMappingAnalyzer
{
    public const CLASS_LESSON_LEVEL = 'LESSON_LEVEL';

    public const CLASS_TOPIC_LEVEL = 'TOPIC_LEVEL';

    public const CLASS_COURSE_LEVEL = 'COURSE_LEVEL';

    public const CLASS_ORPHAN = 'ORPHAN';

    public const CLASS_OUTSIDE_TREE = 'OUTSIDE_AUTHORITATIVE_TREE';

    public const CLASS_AMBIGUOUS = 'AMBIGUOUS_RELATIONSHIP';

    public const CLASS_DUPLICATE = 'DUPLICATE_REFERENCE';

    public const CONFIDENCE_AUTHORITATIVE = 'AUTHORITATIVE_TREE';

    public const CONFIDENCE_EXPLICIT_META = 'EXPLICIT_META';

    public const CONFIDENCE_FALLBACK = 'FALLBACK_APPROVED';

    public const CONFIDENCE_AMBIGUOUS = 'AMBIGUOUS';

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly WordPressCourseTreeAnalyzer $treeAnalyzer,
        private readonly WordPressProQuizAnswerDecoder $answers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(): array
    {
        $ready = $this->connection->assertReadyForImport('posts');
        if (! ($ready['ok'] ?? false)) {
            return [
                'status' => 'blocked',
                'code' => 'WORDPRESS_NOT_READY',
                'wrote_to_database' => false,
                'database_writes' => 0,
                'inspect' => $ready['inspect'] ?? null,
            ];
        }

        $tree = $this->treeAnalyzer->analyze();
        $placements = $this->extractPlacements($tree);
        $classifications = $this->classifyAllQuizzes($tree, $placements);
        $relationships = $this->buildImportableRelationships($placements);
        $course8507 = $this->analyzeCourse8507($tree);
        $stakeholder = $this->findStakeholderLesson($course8507);
        $questionTypes = $this->inventoryQuestionTypes($relationships);
        $attemptHistory = $this->attemptHistory();
        $settings = $this->settingsInventory();

        $supported = array_values(array_filter(
            $relationships,
            fn (array $r) => ($r['import_decision'] ?? '') === 'SUPPORTED_FOR_IMPORT'
        ));
        $deferred = array_values(array_filter(
            $relationships,
            fn (array $r) => ($r['import_decision'] ?? '') !== 'SUPPORTED_FOR_IMPORT'
        ));

        $matrix = [
            'TOTAL_LEARNDASH_QUIZZES' => (int) ($tree['totals']['quizzes_published'] ?? 0),
            'IN_AUTHORITATIVE_TREE' => (int) ($tree['totals']['quizzes_in_tree'] ?? 0),
            'LESSON_LEVEL' => count(array_filter($classifications, fn ($c) => $c['classification'] === self::CLASS_LESSON_LEVEL)),
            'TOPIC_LEVEL' => count(array_filter($classifications, fn ($c) => $c['classification'] === self::CLASS_TOPIC_LEVEL)),
            'COURSE_LEVEL' => count(array_filter($classifications, fn ($c) => $c['classification'] === self::CLASS_COURSE_LEVEL)),
            'ORPHAN' => count(array_filter($classifications, fn ($c) => $c['classification'] === self::CLASS_ORPHAN)),
            'OUTSIDE_TREE' => count(array_filter($classifications, fn ($c) => $c['classification'] === self::CLASS_OUTSIDE_TREE)),
            'AMBIGUOUS' => count(array_filter($classifications, fn ($c) => $c['classification'] === self::CLASS_AMBIGUOUS)),
            'DUPLICATE_REFERENCE' => count(array_filter($classifications, fn ($c) => $c['classification'] === self::CLASS_DUPLICATE)),
            'WITH_VALID_PROQUIZ_MASTER' => count(array_filter($classifications, fn ($c) => ($c['master_status'] ?? '') === 'OK')),
            'WITHOUT_MASTER' => count(array_filter($classifications, fn ($c) => ($c['master_status'] ?? '') !== 'OK')),
            'SUPPORTED_FOR_IMPORT' => count($supported),
            'DEFERRED_UNSUPPORTED_OR_EMPTY' => count($deferred),
            'QUESTIONS_TOTAL' => (int) DB::connection($this->connection->connectionName())
                ->table($this->connection->table('learndash_pro_quiz_question'))->count(),
            'QUESTIONS_IN_SUPPORTED_IMPORT_SCOPE' => array_sum(array_map(
                fn (array $r) => (int) ($r['importable_question_count'] ?? 0),
                $supported
            )),
            'PROQUIZ_MASTERS' => (int) DB::connection($this->connection->connectionName())
                ->table($this->connection->table('learndash_pro_quiz_master'))->count(),
            'COURSE_8507_LESSONS' => (int) ($course8507['lesson_count'] ?? 0),
            'COURSE_8507_LESSONS_WITH_QUIZ' => (int) ($course8507['lessons_with_quiz'] ?? 0),
            'COURSE_8507_LESSONS_WITHOUT_QUIZ' => (int) ($course8507['lessons_without_quiz'] ?? 0),
            'COURSE_8507_QUIZZES' => (int) ($course8507['quiz_count'] ?? 0),
        ];

        $perCourse = [];
        foreach ($tree['courses'] ?? [] as $course) {
            $cid = (int) ($course['course_id'] ?? 0);
            $rels = array_values(array_filter($supported, fn (array $r) => (int) $r['course_legacy_id'] === $cid));
            $perCourse[] = [
                'course_legacy_id' => $cid,
                'title' => $course['title'] ?? '',
                'lessons' => (int) ($course['lesson_count'] ?? 0),
                'tree_quizzes' => (int) ($course['quiz_count'] ?? 0),
                'would_create_quizzes' => count($rels),
                'would_create_questions' => array_sum(array_map(fn ($r) => (int) $r['importable_question_count'], $rels)),
                'would_create_answers' => array_sum(array_map(fn ($r) => (int) $r['importable_option_count'], $rels)),
                'deferred' => count(array_filter(
                    $relationships,
                    fn (array $r) => (int) $r['course_legacy_id'] === $cid
                        && ($r['import_decision'] ?? '') !== 'SUPPORTED_FOR_IMPORT'
                )),
            ];
        }

        return [
            'status' => 'ok',
            'dry_run' => true,
            'wrote_to_database' => false,
            'database_writes' => 0,
            'machine_translation' => false,
            'ai_translation' => false,
            'relationship_authority' => [
                'primary' => 'ld_course_steps',
                'fallback_course_ids' => config('wordpress.course_tree.fallback_course_ids', []),
                'exclude_orphans' => true,
                'no_guess_attachment' => true,
                'course_level_policy' => config('wordpress.course_tree.course_level_quiz_policy'),
            ],
            'tree_status' => $tree['status'] ?? null,
            'tree_totals' => $tree['totals'] ?? [],
            'classifications_summary' => [
                'lesson_level' => $matrix['LESSON_LEVEL'],
                'topic_level' => $matrix['TOPIC_LEVEL'],
                'course_level' => $matrix['COURSE_LEVEL'],
                'orphan' => $matrix['ORPHAN'],
                'outside_tree' => $matrix['OUTSIDE_TREE'],
                'ambiguous' => $matrix['AMBIGUOUS'],
                'duplicate' => $matrix['DUPLICATE_REFERENCE'],
            ],
            'relationships' => $relationships,
            'supported_relationships' => $supported,
            'deferred_relationships' => $deferred,
            'course_8507' => $course8507,
            'stakeholder_lesson' => $stakeholder,
            'question_types' => $questionTypes,
            'attempt_history' => $attemptHistory,
            'settings_inventory' => $settings,
            'matrix' => $matrix,
            'dry_run_totals' => [
                'WOULD_CREATE_QUIZZES' => count($supported),
                'WOULD_MAP_EXISTING' => 0,
                'WOULD_CREATE_QUESTIONS' => array_sum(array_map(fn ($r) => (int) $r['importable_question_count'], $supported)),
                'WOULD_CREATE_ANSWERS' => array_sum(array_map(fn ($r) => (int) $r['importable_option_count'], $supported)),
                'DEFERRED' => count($deferred),
                'SKIPPED_ORPHAN_OR_OUTSIDE' => $matrix['ORPHAN'] + $matrix['OUTSIDE_TREE'],
                'AMBIGUOUS' => $matrix['AMBIGUOUS'],
            ],
            'per_course' => $perCourse,
            'laravel_comparison_note' => 'Production Laravel has native/demo quizzes on courses 1–10 only; course 26 (8507) has 0 quizzes and 0 quiz legacy maps (LIVE_VERIFIED separately).',
            'inspect' => $ready['inspect'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<array<string, mixed>>
     */
    private function extractPlacements(array $tree): array
    {
        $out = [];
        foreach ($tree['courses'] ?? [] as $course) {
            $courseId = (int) ($course['course_id'] ?? 0);
            $relSource = (string) ($course['relationship_source'] ?? 'ld_course_steps');
            $confidence = $relSource === 'course_id_meta_fallback'
                ? self::CONFIDENCE_FALLBACK
                : self::CONFIDENCE_AUTHORITATIVE;

            foreach ($course['course_level_quizzes'] ?? [] as $qid) {
                $out[] = [
                    'quiz_legacy_id' => (int) $qid,
                    'course_legacy_id' => $courseId,
                    'lesson_legacy_id' => null,
                    'topic_legacy_id' => null,
                    'level' => self::CLASS_COURSE_LEVEL,
                    'confidence' => $confidence,
                    'sort_order' => null,
                ];
            }

            foreach ($course['lessons'] ?? [] as $lesson) {
                $lessonId = (int) ($lesson['legacy_id'] ?? 0);
                $sort = (int) ($lesson['sort_order'] ?? 0);
                foreach ($lesson['lesson_level_quiz_ids'] ?? [] as $qid) {
                    $out[] = [
                        'quiz_legacy_id' => (int) $qid,
                        'course_legacy_id' => $courseId,
                        'lesson_legacy_id' => $lessonId,
                        'topic_legacy_id' => null,
                        'level' => self::CLASS_LESSON_LEVEL,
                        'confidence' => $confidence,
                        'sort_order' => $sort,
                    ];
                }
                foreach ($lesson['topics'] ?? [] as $topic) {
                    foreach ($topic['topic_level_quiz_ids'] ?? [] as $qid) {
                        $out[] = [
                            'quiz_legacy_id' => (int) $qid,
                            'course_legacy_id' => $courseId,
                            'lesson_legacy_id' => $lessonId,
                            'topic_legacy_id' => (int) ($topic['legacy_id'] ?? 0),
                            'level' => self::CLASS_TOPIC_LEVEL,
                            'confidence' => $confidence,
                            'sort_order' => (int) ($topic['sort_order'] ?? 0),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<array<string, mixed>>  $placements
     * @return list<array<string, mixed>>
     */
    private function classifyAllQuizzes(array $tree, array $placements): array
    {
        $conn = $this->connection->connectionName();
        $byQuiz = [];
        foreach ($placements as $p) {
            $qid = (int) $p['quiz_legacy_id'];
            if (isset($byQuiz[$qid])) {
                $byQuiz[$qid]['classification'] = self::CLASS_DUPLICATE;
                $byQuiz[$qid]['duplicate_of'] = $byQuiz[$qid]['tree_path'] ?? null;
            } else {
                $byQuiz[$qid] = [
                    'quiz_legacy_id' => $qid,
                    'classification' => $p['level'],
                    'course_legacy_id' => $p['course_legacy_id'],
                    'lesson_legacy_id' => $p['lesson_legacy_id'],
                    'topic_legacy_id' => $p['topic_legacy_id'],
                    'confidence' => $p['confidence'],
                    'tree_path' => $this->path($p),
                    'evidence' => 'ld_course_steps',
                ];
            }
        }

            $orphanIds = array_map('intval', $tree['orphan_quiz_ids'] ?? []);
        $posts = DB::connection($conn)->table($this->connection->table('posts'))
            ->where('post_type', 'sfwd-quiz')
            ->where('post_status', 'publish')
            ->orderBy('ID')
            ->get(['ID', 'post_title']);

        $out = [];
        $orphanSet = array_fill_keys($orphanIds, true);
        foreach ($posts as $post) {
            $qid = (int) $post->ID;
            $row = $byQuiz[$qid] ?? null;
            if ($row === null) {
                $courseMeta = (int) (DB::connection($conn)->table($this->connection->table('postmeta'))
                    ->where('post_id', $qid)->where('meta_key', 'course_id')->value('meta_value') ?? 0);
                $lessonMeta = (int) (DB::connection($conn)->table($this->connection->table('postmeta'))
                    ->where('post_id', $qid)->where('meta_key', 'lesson_id')->value('meta_value') ?? 0);
                $classification = ($courseMeta > 0 || $lessonMeta > 0)
                    ? self::CLASS_OUTSIDE_TREE
                    : self::CLASS_ORPHAN;
                if (! isset($orphanSet[$qid]) && $classification === self::CLASS_ORPHAN) {
                    $classification = self::CLASS_OUTSIDE_TREE;
                }
                $row = [
                    'quiz_legacy_id' => $qid,
                    'classification' => $classification,
                    'course_legacy_id' => $courseMeta ?: null,
                    'lesson_legacy_id' => $lessonMeta ?: null,
                    'topic_legacy_id' => null,
                    'confidence' => self::CONFIDENCE_AMBIGUOUS,
                    'tree_path' => null,
                    'evidence' => $classification === self::CLASS_OUTSIDE_TREE
                        ? 'course_id/lesson_id meta without ld_course_steps placement'
                        : 'no_tree_no_usable_meta',
                ];
            }

            [$masterId, $masterStatus] = $this->resolveMaster($qid);
            $qCount = 0;
            if ($masterStatus === 'OK' && $masterId !== null) {
                $qCount = (int) DB::connection($conn)
                    ->table($this->connection->table('learndash_pro_quiz_question'))
                    ->where('quiz_id', $masterId)
                    ->count();
            }

            $out[] = array_merge($row, [
                'title' => (string) $post->post_title,
                'pro_quiz_master_id' => $masterId,
                'master_status' => $masterStatus,
                'question_count' => $qCount,
            ]);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $placements
     * @return list<array<string, mixed>>
     */
    private function buildImportableRelationships(array $placements): array
    {
        $conn = $this->connection->connectionName();
        $out = [];

        foreach ($placements as $p) {
            if ($p['level'] !== self::CLASS_LESSON_LEVEL) {
                $out[] = array_merge($p, [
                    'title' => null,
                    'pro_quiz_master_id' => null,
                    'question_count' => 0,
                    'importable_question_count' => 0,
                    'importable_option_count' => 0,
                    'import_decision' => $p['level'] === self::CLASS_COURSE_LEVEL
                        ? 'DEFERRED_COURSE_LEVEL_REQUIRES_MODEL_DECISION'
                        : 'DEFERRED_TOPIC_LEVEL',
                    'relationship_source' => $p['confidence'],
                ]);

                continue;
            }

            $qid = (int) $p['quiz_legacy_id'];
            $title = (string) (DB::connection($conn)->table($this->connection->table('posts'))
                ->where('ID', $qid)->value('post_title') ?? '');
            [$masterId, $masterStatus] = $this->resolveMaster($qid);

            $importableQ = 0;
            $importableOpts = 0;
            $scanned = 0;
            $decision = 'SUPPORTED_FOR_IMPORT';

            if ($masterStatus !== 'OK' || $masterId === null) {
                $decision = 'DEFERRED_MISSING_OR_AMBIGUOUS_MASTER';
            } else {
                $questions = DB::connection($conn)
                    ->table($this->connection->table('learndash_pro_quiz_question'))
                    ->where('quiz_id', $masterId)
                    ->orderBy('sort')
                    ->orderBy('id')
                    ->get();
                $scanned = $questions->count();
                if ($scanned === 0) {
                    $decision = 'DEFERRED_EMPTY_QUIZ';
                } else {
                    foreach ($questions as $sq) {
                        $plan = $this->planQuestionSupport((string) ($sq->answer_type ?? ''), isset($sq->answer_data) ? (string) $sq->answer_data : null);
                        if ($plan['ok']) {
                            $importableQ++;
                            $importableOpts += $plan['options'];
                        }
                    }
                    if ($importableQ === 0) {
                        $decision = 'DEFERRED_NO_IMPORTABLE_QUESTIONS';
                    }
                }
            }

            $out[] = [
                'quiz_legacy_id' => $qid,
                'title' => $title,
                'course_legacy_id' => $p['course_legacy_id'],
                'lesson_legacy_id' => $p['lesson_legacy_id'],
                'topic_legacy_id' => null,
                'pro_quiz_master_id' => $masterId,
                'master_status' => $masterStatus,
                'question_count' => $scanned,
                'importable_question_count' => $importableQ,
                'importable_option_count' => $importableOpts,
                'relationship_source' => $p['confidence'],
                'confidence' => $p['confidence'],
                'level' => self::CLASS_LESSON_LEVEL,
                'sort_order' => $p['sort_order'],
                'tree_path' => $this->path($p),
                'import_decision' => $decision,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    private function analyzeCourse8507(array $tree): array
    {
        $conn = $this->connection->connectionName();
        $course = null;
        foreach ($tree['courses'] ?? [] as $c) {
            if ((int) ($c['course_id'] ?? 0) === 8507) {
                $course = $c;
                break;
            }
        }
        if ($course === null) {
            return ['found' => false];
        }

        $lessons = [];
        $with = 0;
        $without = 0;
        $quizCount = 0;
        foreach ($course['lessons'] as $lesson) {
            $lid = (int) $lesson['legacy_id'];
            $title = (string) (DB::connection($conn)->table($this->connection->table('posts'))
                ->where('ID', $lid)->value('post_title') ?? '');
            $quizRows = [];
            foreach ($lesson['lesson_level_quiz_ids'] as $qid) {
                $qid = (int) $qid;
                [$mid, $mst] = $this->resolveMaster($qid);
                $qCount = ($mst === 'OK' && $mid) ? (int) DB::connection($conn)
                    ->table($this->connection->table('learndash_pro_quiz_question'))
                    ->where('quiz_id', $mid)->count() : 0;
                $quizRows[] = [
                    'quiz_legacy_id' => $qid,
                    'title' => (string) (DB::connection($conn)->table($this->connection->table('posts'))
                        ->where('ID', $qid)->value('post_title') ?? ''),
                    'pro_quiz_master_id' => $mid,
                    'question_count' => $qCount,
                ];
                $quizCount++;
            }
            if ($quizRows === []) {
                $without++;
            } else {
                $with++;
            }
            $lessons[] = [
                'sort_order' => (int) $lesson['sort_order'],
                'lesson_legacy_id' => $lid,
                'title' => $title,
                'quizzes' => $quizRows,
                'has_quiz' => $quizRows !== [],
            ];
        }

        return [
            'found' => true,
            'course_legacy_id' => 8507,
            'title' => $course['title'],
            'lesson_count' => count($lessons),
            'topic_count' => (int) ($course['topic_count'] ?? 0),
            'quiz_count' => $quizCount,
            'lessons_with_quiz' => $with,
            'lessons_without_quiz' => $without,
            'lessons' => $lessons,
        ];
    }

    /**
     * @param  array<string, mixed>  $course8507
     * @return array<string, mixed>
     */
    private function findStakeholderLesson(array $course8507): array
    {
        $conn = $this->connection->connectionName();
        $candidates = DB::connection($conn)->table($this->connection->table('posts'))
            ->where('post_type', 'sfwd-lessons')
            ->where('post_status', 'publish')
            ->where('post_title', 'like', '%ما هي الحشرة%')
            ->get(['ID', 'post_title']);

        $inTree = null;
        foreach ($course8507['lessons'] ?? [] as $lesson) {
            if (str_contains((string) $lesson['title'], 'ما هي الحشرة')) {
                $inTree = $lesson;
                break;
            }
        }

        if ($inTree !== null) {
            $has = $inTree['has_quiz'];

            return [
                'matched_title' => $inTree['title'],
                'lesson_legacy_id' => $inTree['lesson_legacy_id'],
                'course_legacy_id' => 8507,
                'in_authoritative_tree' => true,
                'source_has_quiz' => $has ? 'SOURCE_HAS_QUIZ' : 'SOURCE_HAS_NO_QUIZ',
                'quizzes' => $inTree['quizzes'],
                'other_title_matches' => $candidates->map(fn ($r) => [
                    'id' => (int) $r->ID,
                    'title' => $r->post_title,
                ])->all(),
            ];
        }

        return [
            'matched_title' => null,
            'source_has_quiz' => 'NOT_FOUND_IN_8507_TREE',
            'candidates' => $candidates->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @return array<string, mixed>
     */
    private function inventoryQuestionTypes(array $relationships): array
    {
        $conn = $this->connection->connectionName();
        $global = DB::connection($conn)->table($this->connection->table('learndash_pro_quiz_question'))
            ->selectRaw('answer_type, COUNT(*) as c')
            ->groupBy('answer_type')
            ->pluck('c', 'answer_type')
            ->all();

        $scope = [];
        foreach ($relationships as $r) {
            if (($r['import_decision'] ?? '') !== 'SUPPORTED_FOR_IMPORT') {
                continue;
            }
            $mid = $r['pro_quiz_master_id'] ?? null;
            if (! $mid) {
                continue;
            }
            foreach (DB::connection($conn)->table($this->connection->table('learndash_pro_quiz_question'))
                ->where('quiz_id', $mid)->pluck('answer_type') as $t) {
                $scope[(string) $t] = ($scope[(string) $t] ?? 0) + 1;
            }
        }

        $map = [
            'single' => ['laravel' => 'single_choice', 'support' => 'SUPPORTED', 'strategy' => 'map_options_is_correct'],
            'multiple' => ['laravel' => 'multiple_choice', 'support' => 'SUPPORTED', 'strategy' => 'map_options_is_correct'],
            'essay' => ['laravel' => 'long_answer', 'support' => 'SUPPORTED', 'strategy' => 'manual_review'],
            'sort_answer' => ['laravel' => 'ordering', 'support' => 'SUPPORTED', 'strategy' => 'option_order'],
            'matrix_sort_answer' => ['laravel' => 'matching', 'support' => 'SUPPORTED', 'strategy' => 'left_right_match_key'],
        ];

        $rows = [];
        foreach ($global as $type => $count) {
            $m = $map[$type] ?? ['laravel' => null, 'support' => 'UNSUPPORTED', 'strategy' => 'DEFER'];
            $rows[] = [
                'SOURCE_TYPE' => $type,
                'COUNT_GLOBAL' => (int) $count,
                'COUNT_IN_SUPPORTED_SCOPE' => (int) ($scope[$type] ?? 0),
                'LARAVEL_SUPPORT' => $m['support'],
                'LARAVEL_TYPE' => $m['laravel'],
                'MAPPING_STRATEGY' => $m['strategy'],
                'BLOCKER' => $m['support'] === 'UNSUPPORTED' ? 'NO_SAFE_SEMANTIC_MAPPING' : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptHistory(): array
    {
        $conn = $this->connection->connectionName();
        $stat = (int) DB::connection($conn)->table($this->connection->table('learndash_pro_quiz_statistic_ref'))->count();
        $activity = (int) DB::connection($conn)->table($this->connection->table('learndash_user_activity'))
            ->where('activity_type', 'quiz')->count();

        return [
            'verdict' => ($stat > 0 || $activity > 0)
                ? 'QUIZ_ATTEMPT_HISTORY_EXISTS'
                : 'QUIZ_ATTEMPT_HISTORY_NOT_FOUND',
            'pro_quiz_statistic_ref' => $stat,
            'learndash_user_activity_quiz' => $activity,
            'import_in_this_phase' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsInventory(): array
    {
        return [
            'laravel_supported' => [
                'passing_score' => 'from _sfwd-quiz passingpercentage / certificate threshold (default 70)',
                'time_limit_seconds' => 'pro_quiz_master.time_limit when > 0',
                'max_attempts' => '1 when quiz_run_once=1 else null',
                'shuffle_questions' => 'question_random',
                'is_required' => 'ALWAYS false for historical import',
                'selection_mode' => 'Fixed',
            ],
            'source_not_mapped' => [
                'answer_random' => 'recorded in map metadata only; Laravel has no answer shuffle flag applied',
                'statistics_on' => 'not mapped',
                'toplist' => 'not mapped',
                'prerequisite' => 'not mapped',
                'email_notification' => 'not mapped',
                'show/hide result UI flags' => 'not mapped',
                'form_activated' => 'not mapped',
            ],
        ];
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    private function resolveMaster(int $quizPostId): array
    {
        $wp = DB::connection($this->connection->connectionName());
        $ids = [];
        $pid = $wp->table($this->connection->table('postmeta'))
            ->where('post_id', $quizPostId)
            ->where('meta_key', 'quiz_pro_id')
            ->value('meta_value');
        if (is_numeric($pid) && (int) $pid > 0) {
            $ids[(int) $pid] = true;
        }

        $sfwd = $wp->table($this->connection->table('postmeta'))
            ->where('post_id', $quizPostId)
            ->where('meta_key', '_sfwd-quiz')
            ->value('meta_value');
        if (is_string($sfwd) && $sfwd !== '') {
            $data = @unserialize($sfwd, ['allowed_classes' => false]);
            if (is_array($data)) {
                foreach (['sfwd-quiz_quiz_pro', 'quiz_pro', 'quiz_pro_id'] as $key) {
                    if (isset($data[$key]) && is_numeric($data[$key]) && (int) $data[$key] > 0) {
                        $ids[(int) $data[$key]] = true;
                    }
                }
            }
        }

        $list = array_keys($ids);
        if ($list === []) {
            return [null, 'MISSING'];
        }
        if (count($list) > 1) {
            return [-1, 'AMBIGUOUS'];
        }
        $mid = $list[0];
        $exists = $wp->table($this->connection->table('learndash_pro_quiz_master'))
            ->where('id', $mid)->exists();

        return $exists ? [$mid, 'OK'] : [null, 'MASTER_ROW_MISSING'];
    }

    /**
     * @return array{ok: bool, options: int}
     */
    private function planQuestionSupport(string $type, ?string $answerData): array
    {
        $decoded = $this->answers->decode($answerData);
        if ($decoded === null) {
            return ['ok' => false, 'options' => 0];
        }
        if ($type === 'single' || $type === 'multiple') {
            if ($decoded === []) {
                return ['ok' => false, 'options' => 0];
            }
            $correct = 0;
            foreach ($decoded as $opt) {
                if ($opt['correct']) {
                    $correct++;
                }
            }
            if ($correct === 0) {
                return ['ok' => false, 'options' => 0];
            }

            return ['ok' => true, 'options' => count($decoded)];
        }
        if ($type === 'essay') {
            return ['ok' => true, 'options' => 0];
        }
        if ($type === 'sort_answer') {
            return $decoded === [] ? ['ok' => false, 'options' => 0] : ['ok' => true, 'options' => count($decoded)];
        }
        if ($type === 'matrix_sort_answer') {
            return $decoded === [] ? ['ok' => false, 'options' => 0] : ['ok' => true, 'options' => count($decoded) * 2];
        }

        return ['ok' => false, 'options' => 0];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function path(array $p): string
    {
        $parts = ['course:'.$p['course_legacy_id']];
        if ($p['lesson_legacy_id']) {
            $parts[] = 'lesson:'.$p['lesson_legacy_id'];
        }
        if ($p['topic_legacy_id']) {
            $parts[] = 'topic:'.$p['topic_legacy_id'];
        }
        $parts[] = 'quiz:'.$p['quiz_legacy_id'];

        return implode('/', $parts);
    }
}
