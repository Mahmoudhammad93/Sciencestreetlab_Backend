<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Historical LearnDash / Pro Quiz definition importer (R8.8B).
 *
 * Scope: approved course-tree lesson-level sfwd-quiz only.
 * Historical quizzes are ALWAYS is_required=false.
 * Attempts / progress / media are out of scope.
 */
final class WordPressQuizImporter
{
    public const ENTITY_QUIZ = 'quiz';

    public const ENTITY_QUESTION = 'question';

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly WordPressCourseTreeAnalyzer $treeAnalyzer,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
        private readonly WordPressProQuizAnswerDecoder $answers,
        private readonly MigrationRunService $runs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(int $migrationRunId, bool $dryRun = true): array
    {
        // Bind running run before gate ActiveMigrationRun check (same pattern as CLI authorizeMutation).
        if (! $dryRun && $this->persistGate->realPersistEnabled()) {
            $this->runs->bindRunning($migrationRunId);
        }

        $blocked = $this->persistGate->importerBlockIfUnauthorized($dryRun, self::ENTITY_QUIZ);
        if ($blocked !== null) {
            return array_merge($this->emptyReport($migrationRunId, false), $blocked, [
                'migration_run_id' => $migrationRunId,
                'attempt_history_imported' => 0,
                'progress_mutations' => 0,
                'writes' => false,
                'side_effects' => false,
            ]);
        }

        if ($dryRun) {
            $run = \App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun::query()->find($migrationRunId);
            if ($run === null || $run->status !== MigrationRunService::STATUS_RUNNING) {
                throw new \RuntimeException("Migration run {$migrationRunId} must exist and be running.");
            }
        }

        $this->connection->assertProductionPrefix();

        $httpBefore = $this->httpRequestSnapshot();
        $report = $this->emptyReport($migrationRunId, $dryRun);

        $tree = $this->treeAnalyzer->analyze();
        $placements = $this->extractTreePlacements($tree);
        $report['tree_quizzes_scanned'] = count($placements);
        $report['tree_unique_quizzes'] = count($placements);

        if ((int) ($tree['totals']['quizzes_in_tree'] ?? 0) !== count($placements)) {
            $report['status'] = 'blocked';
            $report['code'] = 'TREE_SCOPE_MISMATCH';
            $report['message'] = 'Analyzer tree quiz count does not match extracted lesson-level placements.';
            $report['analyzer_quizzes_in_tree'] = $tree['totals']['quizzes_in_tree'] ?? null;

            return $report;
        }

        $lessonMaps = $this->loadEntityMaps($migrationRunId, 'lesson');
        $wp = DB::connection($this->connection->connectionName());

        foreach ($placements as $placement) {
            $legacyQuizId = (string) $placement['quiz_legacy_id'];
            $legacyLessonId = (string) $placement['lesson_legacy_id'];

            $existingQuizMap = $this->findRunMap($migrationRunId, self::ENTITY_QUIZ, $legacyQuizId);
            if ($existingQuizMap?->local_id) {
                $report['quiz_skip_mapped']++;

                continue;
            }

            if (! isset($lessonMaps[$legacyLessonId])) {
                $report['quiz_defer_missing_parent']++;
                $report['deferred'][] = [
                    'reason' => 'MISSING_PARENT_LESSON_MAP',
                    'legacy_quiz_id' => $legacyQuizId,
                    'legacy_lesson_id' => $legacyLessonId,
                ];

                continue;
            }

            $lessonLocalId = $lessonMaps[$legacyLessonId];
            $lesson = Lesson::query()->find($lessonLocalId);
            if ($lesson === null) {
                $report['quiz_defer_missing_parent']++;
                $report['deferred'][] = [
                    'reason' => 'PARENT_LESSON_ROW_MISSING',
                    'legacy_quiz_id' => $legacyQuizId,
                    'legacy_lesson_id' => $legacyLessonId,
                    'local_lesson_id' => $lessonLocalId,
                ];

                continue;
            }

            $masterId = $this->resolveMasterId($wp, (int) $legacyQuizId);
            if ($masterId === null) {
                $report['quiz_defer_missing_master']++;
                $report['deferred'][] = [
                    'reason' => 'MISSING_PRO_QUIZ_MASTER',
                    'legacy_quiz_id' => $legacyQuizId,
                ];

                continue;
            }
            if ($masterId === -1) {
                $report['quiz_defer_ambiguous_master']++;
                $report['deferred'][] = [
                    'reason' => 'AMBIGUOUS_PRO_QUIZ_MASTER',
                    'legacy_quiz_id' => $legacyQuizId,
                ];

                continue;
            }

            $master = $wp->table($this->connection->table('learndash_pro_quiz_master'))
                ->where('id', $masterId)
                ->first();
            if ($master === null) {
                $report['quiz_defer_missing_master']++;
                $report['deferred'][] = [
                    'reason' => 'MASTER_ROW_MISSING',
                    'legacy_quiz_id' => $legacyQuizId,
                    'pro_quiz_master_id' => $masterId,
                ];

                continue;
            }

            $sourceQuestions = $wp->table($this->connection->table('learndash_pro_quiz_question'))
                ->where('quiz_id', $masterId)
                ->orderBy('sort')
                ->orderBy('id')
                ->get();

            if ($sourceQuestions->isEmpty()) {
                $report['quiz_defer_empty']++;
                $report['deferred'][] = [
                    'reason' => 'EMPTY_QUIZ',
                    'legacy_quiz_id' => $legacyQuizId,
                    'pro_quiz_master_id' => $masterId,
                ];

                continue;
            }

            $quizPost = $wp->table($this->connection->table('posts'))
                ->where('ID', (int) $legacyQuizId)
                ->first(['ID', 'post_title', 'post_content', 'post_status']);

            $passing = $this->resolvePassingScore($wp, (int) $legacyQuizId);
            $report['passing_score_distribution'][(string) $passing] =
                ($report['passing_score_distribution'][(string) $passing] ?? 0) + 1;

            $plannedQuestions = [];
            $quizHasCreatableQuestion = false;

            foreach ($sourceQuestions as $sq) {
                $report['question_scanned']++;
                $legacyQuestionId = (string) $sq->id;
                $type = (string) ($sq->answer_type ?? '');

                match ($type) {
                    'single' => $report['question_single']++,
                    'multiple' => $report['question_multiple']++,
                    'essay' => $report['question_essay']++,
                    'matrix_sort_answer' => $report['question_matrix_sort']++,
                    'sort_answer' => $report['question_sort']++,
                    default => null,
                };

                $existingQ = $this->findRunMap($migrationRunId, self::ENTITY_QUESTION, $legacyQuestionId);
                if ($existingQ?->local_id) {
                    $report['question_skip_mapped']++;

                    continue;
                }

                $decoded = $this->answers->decode(isset($sq->answer_data) ? (string) $sq->answer_data : null);
                if ($decoded === null) {
                    $report['question_defer']++;
                    $report['deferred'][] = [
                        'reason' => 'MALFORMED_ANSWER_DATA',
                        'legacy_quiz_id' => $legacyQuizId,
                        'legacy_question_id' => $legacyQuestionId,
                        'answer_type' => $type,
                    ];

                    continue;
                }

                $plan = $this->planQuestion($type, $decoded, $sq, $legacyQuizId);
                if ($plan['defer']) {
                    $report['question_defer']++;
                    if (($plan['code'] ?? '') === 'EMPTY_ANSWER_DATA') {
                        $report['question_empty_answer_data']++;
                    }
                    if (($plan['code'] ?? '') === 'ZERO_CORRECT') {
                        $report['question_zero_correct']++;
                    }
                    if (($plan['code'] ?? '') === 'UNSUPPORTED_TYPE') {
                        $report['question_unsupported']++;
                    }
                    $report['deferred'][] = [
                        'reason' => $plan['code'] ?? 'QUESTION_DEFER',
                        'legacy_quiz_id' => $legacyQuizId,
                        'legacy_question_id' => $legacyQuestionId,
                        'answer_type' => $type,
                    ];

                    continue;
                }

                $bodyText = (string) ($sq->question ?? '');
                if (str_contains($bodyText, 'wp-content/uploads')) {
                    $report['media_pending_questions']++;
                    $plan['media_pending'] = true;
                }
                if (preg_match('/\[[a-z0-9_-]+/i', $bodyText) === 1) {
                    $report['shortcode_questions']++;
                    $plan['has_shortcode'] = true;
                }

                $quizHasCreatableQuestion = true;
                $plannedQuestions[] = $plan;
                $report['question_create']++;
                $report['options_create'] += count($plan['options'] ?? []);
            }

            if (! $quizHasCreatableQuestion) {
                // All questions deferred → treat quiz as deferred-other (do not create empty shell).
                $report['quiz_defer_empty']++;
                $report['deferred'][] = [
                    'reason' => 'NO_IMPORTABLE_QUESTIONS',
                    'legacy_quiz_id' => $legacyQuizId,
                    'pro_quiz_master_id' => $masterId,
                ];

                continue;
            }

            // Historical quizzes must never be required.
            $report['required_true_proposed'] += 0;
            $report['quiz_create']++;

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use (
                $migrationRunId,
                $legacyQuizId,
                $legacyLessonId,
                $lesson,
                $master,
                $masterId,
                $quizPost,
                $passing,
                $plannedQuestions,
            ): void {
                $quiz = Quiz::query()->create([
                    'quizable_type' => Lesson::class,
                    'quizable_id' => $lesson->id,
                    'passing_score' => $passing,
                    'max_attempts' => ((int) ($master->quiz_run_once ?? 0) === 1) ? 1 : null,
                    'time_limit_seconds' => ((int) ($master->time_limit ?? 0) > 0)
                        ? (int) $master->time_limit
                        : null,
                    'shuffle_questions' => ((int) ($master->question_random ?? 0) === 1),
                    'is_required' => false,
                    'selection_mode' => QuizSelectionMode::Fixed,
                    'title' => ['ar' => (string) ($quizPost?->post_title ?: ($master->name ?? 'Quiz '.$legacyQuizId))],
                    'instructions' => filled($master->text ?? null)
                        ? ['ar' => (string) $master->text]
                        : null,
                ]);

                $this->maps->upsertMapping(self::ENTITY_QUIZ, $legacyQuizId, [
                    'migration_run_id' => $migrationRunId,
                    'local_id' => $quiz->id,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipCreated([
                        'pro_quiz_master_id' => $masterId,
                        'legacy_lesson_id' => $legacyLessonId,
                        'lesson_local_id' => $lesson->id,
                        'is_required' => false,
                        'historical_import' => true,
                        'answer_random_source' => (int) ($master->answer_random ?? 0) === 1,
                        'answer_random_applied' => false,
                    ]),
                ]);

                foreach ($plannedQuestions as $plan) {
                    $this->persistQuestion($migrationRunId, $quiz, $plan);
                }
            });
        }

        $httpAfter = $this->httpRequestSnapshot();
        $report['external_requests'] = max(0, $httpAfter - $httpBefore);
        $report['writes'] = ! $dryRun && ($report['quiz_create'] > 0 || $report['question_create'] > 0);
        $report['wrote_to_database'] = $report['writes'];
        $report['side_effects'] = false;
        $report['attempt_history_imported'] = 0;
        $report['progress_mutations'] = 0;
        $report['status'] = $dryRun ? 'dry_run' : 'ok';
        $report['matrix_sort_policy'] = 'IMPORT_AS_MATCHING';
        $report['essay_policy'] = 'LONG_ANSWER_MANUAL_REVIEW';
        $report['empty_quiz_policy'] = 'DEFER';
        $report['historical_quiz_is_required'] = false;

        return $report;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<array{quiz_legacy_id: int, lesson_legacy_id: int, course_legacy_id: int, sort_order: int}>
     */
    private function extractTreePlacements(array $tree): array
    {
        $out = [];
        foreach ($tree['courses'] ?? [] as $course) {
            $courseId = (int) ($course['course_id'] ?? 0);
            foreach ($course['lessons'] ?? [] as $lesson) {
                $lessonId = (int) ($lesson['legacy_id'] ?? 0);
                $sort = (int) ($lesson['sort_order'] ?? 0);
                foreach ($lesson['lesson_level_quiz_ids'] ?? [] as $qid) {
                    $out[] = [
                        'quiz_legacy_id' => (int) $qid,
                        'lesson_legacy_id' => $lessonId,
                        'course_legacy_id' => $courseId,
                        'sort_order' => $sort,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, int> legacy_id => local_id
     */
    private function loadEntityMaps(int $migrationRunId, string $entityType): array
    {
        $maps = [];
        LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('migration_run_id', $migrationRunId)
            ->where('entity_type', $entityType)
            ->whereNotNull('local_id')
            ->orderBy('id')
            ->get(['legacy_id', 'local_id'])
            ->each(function ($row) use (&$maps): void {
                $maps[(string) $row->legacy_id] = (int) $row->local_id;
            });

        return $maps;
    }

    private function findRunMap(int $migrationRunId, string $entityType, string $legacyId): ?LegacyImportMap
    {
        return LegacyImportMap::query()
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('migration_run_id', $migrationRunId)
            ->where('entity_type', $entityType)
            ->where('legacy_id', $legacyId)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return int|null master id, -1 ambiguous, null missing
     */
    private function resolveMasterId(mixed $wp, int $quizPostId): ?int
    {
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
            try {
                $data = @unserialize($sfwd, ['allowed_classes' => false]);
            } catch (Throwable) {
                $data = null;
            }
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
            return null;
        }
        if (count($list) > 1) {
            return -1;
        }

        $mid = $list[0];
        $exists = $wp->table($this->connection->table('learndash_pro_quiz_master'))
            ->where('id', $mid)
            ->exists();

        return $exists ? $mid : null;
    }

    private function resolvePassingScore(mixed $wp, int $quizPostId): float
    {
        $sfwd = $wp->table($this->connection->table('postmeta'))
            ->where('post_id', $quizPostId)
            ->where('meta_key', '_sfwd-quiz')
            ->value('meta_value');
        $passing = null;
        if (is_string($sfwd) && $sfwd !== '') {
            try {
                $data = @unserialize($sfwd, ['allowed_classes' => false]);
            } catch (Throwable) {
                $data = null;
            }
            if (is_array($data)) {
                foreach (['sfwd-quiz_passingpercentage', 'sfwd-quiz_passing_percentage', 'passingpercentage'] as $key) {
                    if (isset($data[$key]) && $data[$key] !== '' && is_numeric($data[$key])) {
                        $passing = (float) $data[$key];
                        break;
                    }
                }
            }
        }

        $th = $wp->table($this->connection->table('postmeta'))
            ->where('post_id', $quizPostId)
            ->where('meta_key', '_ld_certificate_threshold')
            ->value('meta_value');
        if ($passing === null && $th !== null && $th !== '' && is_numeric($th)) {
            $th = (float) $th;
            $passing = $th <= 1.5 ? round($th * 100, 2) : $th;
        }

        return $passing ?? 70.0;
    }

    /**
     * @param  list<array<string, mixed>>  $decoded
     * @return array<string, mixed>
     */
    private function planQuestion(string $type, array $decoded, object $sq, string $legacyQuizId): array
    {
        $legacyQuestionId = (string) $sq->id;
        $base = [
            'defer' => false,
            'legacy_question_id' => $legacyQuestionId,
            'legacy_quiz_id' => $legacyQuizId,
            'sort_order' => (int) ($sq->sort ?? 0),
            'points' => (float) ($sq->points ?? 1),
            'body' => (string) ($sq->question ?? ''),
            'title' => (string) ($sq->title ?? ''),
            'explanation' => trim((string) ($sq->correct_msg ?? '')."\n".(string) ($sq->incorrect_msg ?? '')),
            'options' => [],
            'media_pending' => false,
            'has_shortcode' => false,
        ];

        if ($type === 'single') {
            if ($decoded === []) {
                return array_merge($base, ['defer' => true, 'code' => 'EMPTY_ANSWER_DATA']);
            }
            $correct = 0;
            foreach ($decoded as $i => $opt) {
                if ($opt['correct']) {
                    $correct++;
                }
                $base['options'][] = [
                    'label' => $opt['answer'],
                    'is_correct' => $opt['correct'],
                    'sort_order' => $i,
                    'meta' => null,
                ];
            }
            if ($correct === 0) {
                return array_merge($base, ['defer' => true, 'code' => 'ZERO_CORRECT', 'options' => []]);
            }
            $base['question_type'] = QuestionType::SingleChoice;

            return $base;
        }

        if ($type === 'multiple') {
            if ($decoded === []) {
                return array_merge($base, ['defer' => true, 'code' => 'EMPTY_ANSWER_DATA']);
            }
            $correct = 0;
            foreach ($decoded as $i => $opt) {
                if ($opt['correct']) {
                    $correct++;
                }
                $base['options'][] = [
                    'label' => $opt['answer'],
                    'is_correct' => $opt['correct'],
                    'sort_order' => $i,
                    'meta' => null,
                ];
            }
            if ($correct === 0) {
                return array_merge($base, ['defer' => true, 'code' => 'ZERO_CORRECT', 'options' => []]);
            }
            $base['question_type'] = QuestionType::MultipleChoice;

            return $base;
        }

        if ($type === 'essay') {
            $base['question_type'] = QuestionType::LongAnswer;
            $base['options'] = [];
            $base['manual_review'] = true;

            return $base;
        }

        if ($type === 'sort_answer') {
            if ($decoded === []) {
                return array_merge($base, ['defer' => true, 'code' => 'EMPTY_ANSWER_DATA']);
            }
            foreach ($decoded as $i => $opt) {
                $base['options'][] = [
                    'label' => $opt['answer'] !== '' ? $opt['answer'] : $opt['sort_string'],
                    'is_correct' => false,
                    'sort_order' => $i,
                    'meta' => ['source' => 'sort_answer'],
                ];
            }
            $base['question_type'] = QuestionType::Ordering;

            return $base;
        }

        if ($type === 'matrix_sort_answer') {
            if ($decoded === []) {
                return array_merge($base, ['defer' => true, 'code' => 'EMPTY_ANSWER_DATA']);
            }
            // Map to Matching: left=_sortString (criterion), right=_answer (item), shared match_key.
            $sort = 0;
            foreach ($decoded as $i => $opt) {
                $key = 'm'.$i;
                $leftLabel = $opt['sort_string'] !== '' ? $opt['sort_string'] : ('criterion-'.$i);
                $rightLabel = $opt['answer'] !== '' ? $opt['answer'] : ('item-'.$i);
                $base['options'][] = [
                    'label' => $leftLabel,
                    'is_correct' => false,
                    'sort_order' => $sort++,
                    'meta' => ['side' => 'left', 'match_key' => $key, 'source' => 'matrix_sort_answer'],
                ];
                $base['options'][] = [
                    'label' => $rightLabel,
                    'is_correct' => false,
                    'sort_order' => $sort++,
                    'meta' => ['side' => 'right', 'match_key' => $key, 'source' => 'matrix_sort_answer'],
                ];
            }
            $base['question_type'] = QuestionType::Matching;

            return $base;
        }

        return array_merge($base, ['defer' => true, 'code' => 'UNSUPPORTED_TYPE']);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function persistQuestion(int $migrationRunId, Quiz $quiz, array $plan): void
    {
        /** @var QuestionType $type */
        $type = $plan['question_type'];
        $body = (string) $plan['body'];
        $explanation = trim((string) ($plan['explanation'] ?? ''));

        $question = Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => $type,
            'difficulty' => QuestionDifficulty::Medium,
            'status' => QuestionStatus::Published,
            'points' => (float) $plan['points'],
            'sort_order' => (int) $plan['sort_order'],
            'body' => ['ar' => $body !== '' ? $body : (string) ($plan['title'] ?? '')],
            'explanation' => $explanation !== '' ? ['ar' => $explanation] : null,
        ]);

        foreach ($plan['options'] ?? [] as $opt) {
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'is_correct' => (bool) ($opt['is_correct'] ?? false),
                'sort_order' => (int) ($opt['sort_order'] ?? 0),
                'label' => ['ar' => (string) ($opt['label'] ?? '')],
                'meta' => $opt['meta'] ?? null,
            ]);
        }

        $this->maps->upsertMapping(self::ENTITY_QUESTION, (string) $plan['legacy_question_id'], [
            'migration_run_id' => $migrationRunId,
            'local_id' => $question->id,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'quiz_local_id' => $quiz->id,
                'legacy_quiz_id' => $plan['legacy_quiz_id'],
                'answer_type_source' => $type->value,
                'media_pending' => (bool) ($plan['media_pending'] ?? false),
                'has_shortcode' => (bool) ($plan['has_shortcode'] ?? false),
                'manual_review' => (bool) ($plan['manual_review'] ?? false),
                'option_count' => count($plan['options'] ?? []),
                'historical_import' => true,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(int $migrationRunId, bool $dryRun): array
    {
        return [
            'status' => $dryRun ? 'dry_run' : 'ok',
            'entity_type' => self::ENTITY_QUIZ,
            'dry_run' => $dryRun,
            'migration_run_id' => $migrationRunId,
            'wrote_to_database' => false,
            'writes' => false,
            'tree_quizzes_scanned' => 0,
            'tree_unique_quizzes' => 0,
            'quiz_create' => 0,
            'quiz_skip_mapped' => 0,
            'quiz_defer_empty' => 0,
            'quiz_defer_missing_parent' => 0,
            'quiz_defer_missing_master' => 0,
            'quiz_defer_ambiguous_master' => 0,
            'question_scanned' => 0,
            'question_create' => 0,
            'question_defer' => 0,
            'question_skip_mapped' => 0,
            'question_single' => 0,
            'question_multiple' => 0,
            'question_essay' => 0,
            'question_matrix_sort' => 0,
            'question_sort' => 0,
            'question_empty_answer_data' => 0,
            'question_zero_correct' => 0,
            'question_unsupported' => 0,
            'options_create' => 0,
            'media_pending_questions' => 0,
            'shortcode_questions' => 0,
            'passing_score_distribution' => [],
            'required_true_proposed' => 0,
            'attempt_history_imported' => 0,
            'progress_mutations' => 0,
            'native_collisions' => 0,
            'external_requests' => 0,
            'side_effects' => false,
            'deferred' => [],
        ];
    }

    private function httpRequestSnapshot(): int
    {
        // Http facade records recorded responses in tests; production count stays 0 unless faked.
        try {
            return count(Http::recorded());
        } catch (Throwable) {
            return 0;
        }
    }
}
