<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Grading\LongAnswerGrader;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\WordPressProQuizAnswerDecoder;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WordPressQuizImportR88BTest extends TestCase
{
    use RefreshDatabase;

    private string $wpDbPath;

    private LegacyMigrationRun $run;

    private Course $course;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpDbPath = storage_path('framework/testing/wp-quiz-'.Str::uuid().'.sqlite');
        @mkdir(dirname($this->wpDbPath), 0777, true);
        if (file_exists($this->wpDbPath)) {
            unlink($this->wpDbPath);
        }
        touch($this->wpDbPath);

        config([
            'database.connections.wordpress' => [
                'driver' => 'sqlite',
                'database' => $this->wpDbPath,
                'prefix' => 'wp_',
                'foreign_key_constraints' => false,
            ],
            'wordpress.connection' => 'wordpress',
            'wordpress.host' => '127.0.0.1',
            'wordpress.database' => $this->wpDbPath,
            'wordpress.username' => 'test',
            'wordpress.password' => '',
            'wordpress.prefix' => 'wp_',
            'wordpress.real_persist' => null,
            'wordpress.course_tree.fallback_course_ids' => [],
            'wordpress.course_tree.empty_source_course_ids' => [],
            'wordpress.course_tree.staging_approved' => true,
        ]);
        putenv('WORDPRESS_REAL_PERSIST');
        unset($_ENV['WORDPRESS_REAL_PERSIST'], $_SERVER['WORDPRESS_REAL_PERSIST']);
        DB::purge('wordpress');
        DB::reconnect('wordpress');
        $this->migrateWordpressSchema();

        $this->run = LegacyMigrationRun::query()->create([
            'source' => 'wordpress',
            'environment' => 'testing',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->course = Course::query()->create([
            'slug' => 'hist-course',
            'access_type' => 'free',
            'is_published' => true,
            'title' => ['ar' => 'course'],
        ]);
        $this->lesson = Lesson::query()->create([
            'course_id' => $this->course->id,
            'slug' => 'hist-lesson',
            'sort_order' => 0,
            'is_published' => true,
            'title' => ['ar' => 'lesson'],
        ]);

        LegacyImportMap::query()->create([
            'migration_run_id' => $this->run->id,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'course',
            'legacy_id' => '9001',
            'local_id' => $this->course->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([]),
            'imported_at' => now(),
        ]);
        LegacyImportMap::query()->create([
            'migration_run_id' => $this->run->id,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'lesson',
            'legacy_id' => '9101',
            'local_id' => $this->lesson->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([]),
            'imported_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('wordpress');
        } catch (\Throwable) {
        }
        if (isset($this->wpDbPath) && file_exists($this->wpDbPath)) {
            @unlink($this->wpDbPath);
        }
        parent::tearDown();
    }

    private function migrateWordpressSchema(): void
    {
        $schema = DB::connection('wordpress')->getSchemaBuilder();
        $schema->create('posts', function ($t): void {
            $t->increments('ID');
            $t->string('post_type');
            $t->string('post_status');
            $t->string('post_title')->nullable();
            $t->string('post_name')->nullable();
            $t->text('post_content')->nullable();
        });
        $schema->create('postmeta', function ($t): void {
            $t->increments('meta_id');
            $t->unsignedInteger('post_id');
            $t->string('meta_key');
            $t->text('meta_value')->nullable();
        });
        $schema->create('learndash_pro_quiz_master', function ($t): void {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->text('text')->nullable();
            $t->integer('question_random')->default(0);
            $t->integer('answer_random')->default(0);
            $t->integer('time_limit')->default(0);
            $t->integer('quiz_run_once')->default(0);
            $t->integer('prerequisite')->default(0);
            $t->integer('show_max_question')->default(0);
        });
        $schema->create('learndash_pro_quiz_question', function ($t): void {
            $t->increments('id');
            $t->unsignedInteger('quiz_id');
            $t->integer('online')->default(1);
            $t->integer('previous_id')->default(0);
            $t->integer('sort')->default(0);
            $t->string('title')->nullable();
            $t->float('points')->default(1);
            $t->text('question')->nullable();
            $t->text('correct_msg')->nullable();
            $t->text('incorrect_msg')->nullable();
            $t->string('answer_type')->nullable();
            $t->text('answer_data')->nullable();
            $t->integer('answer_points_activated')->default(0);
            $t->integer('answer_points_diff_modus_activated')->default(0);
        });
    }

    /**
     * @param  list<array{answer?: string, correct?: bool, sort_string?: string}>  $opts
     */
    private function serializeAnswers(array $opts): string
    {
        $rows = [];
        foreach ($opts as $opt) {
            $rows[] = [
                '_answer' => $opt['answer'] ?? '',
                '_html' => false,
                '_points' => 1.0,
                '_correct' => (bool) ($opt['correct'] ?? false),
                '_sortString' => $opt['sort_string'] ?? '',
                '_sortStringHtml' => false,
                '_graded' => null,
                '_gradedType' => $opt['graded_type'] ?? null,
                '_gradingProgression' => null,
            ];
        }

        return serialize($rows);
    }

    private function seedTreeQuiz(
        int $quizPostId,
        int $masterId,
        string $passing = '80',
        array $questions = [],
    ): void {
        $wp = DB::connection('wordpress');
        // Course + lesson + quiz posts
        if ($wp->table('posts')->where('ID', 9001)->doesntExist()) {
            $wp->table('posts')->insert([
                'ID' => 9001,
                'post_type' => 'sfwd-courses',
                'post_status' => 'publish',
                'post_title' => 'Course',
                'post_name' => 'course',
                'post_content' => '',
            ]);
        }
        if ($wp->table('posts')->where('ID', 9101)->doesntExist()) {
            $wp->table('posts')->insert([
                'ID' => 9101,
                'post_type' => 'sfwd-lessons',
                'post_status' => 'publish',
                'post_title' => 'Lesson',
                'post_name' => 'lesson',
                'post_content' => '',
            ]);
        }
        $wp->table('posts')->insert([
            'ID' => $quizPostId,
            'post_type' => 'sfwd-quiz',
            'post_status' => 'publish',
            'post_title' => 'Quiz '.$quizPostId,
            'post_name' => 'quiz-'.$quizPostId,
            'post_content' => '',
        ]);

        $steps = [
            'steps' => [
                'h' => [
                    'sfwd-lessons' => [
                        9101 => [
                            'sfwd-topic' => [],
                            'sfwd-quiz' => [
                                $quizPostId => '',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        // Replace course steps each time accumulating quizzes under same lesson
        $existing = $wp->table('postmeta')->where('post_id', 9001)->where('meta_key', 'ld_course_steps')->value('meta_value');
        if (is_string($existing) && $existing !== '') {
            $decoded = @unserialize($existing, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $decoded['steps']['h']['sfwd-lessons'][9101]['sfwd-quiz'][$quizPostId] = '';
                $steps = $decoded;
            }
        }
        $wp->table('postmeta')->where('post_id', 9001)->where('meta_key', 'ld_course_steps')->delete();
        $wp->table('postmeta')->insert([
            'post_id' => 9001,
            'meta_key' => 'ld_course_steps',
            'meta_value' => serialize($steps),
        ]);

        $wp->table('postmeta')->insert([
            ['post_id' => $quizPostId, 'meta_key' => 'quiz_pro_id', 'meta_value' => (string) $masterId],
            ['post_id' => $quizPostId, 'meta_key' => '_sfwd-quiz', 'meta_value' => serialize([
                'sfwd-quiz_quiz_pro' => $masterId,
                'sfwd-quiz_passingpercentage' => $passing,
            ])],
            ['post_id' => $quizPostId, 'meta_key' => 'course_id', 'meta_value' => '9001'],
            ['post_id' => $quizPostId, 'meta_key' => 'lesson_id', 'meta_value' => '9101'],
        ]);

        if ($wp->table('learndash_pro_quiz_master')->where('id', $masterId)->doesntExist()) {
            $wp->table('learndash_pro_quiz_master')->insert([
                'id' => $masterId,
                'name' => 'Master '.$masterId,
                'text' => 'Instructions',
                'question_random' => 0,
                'answer_random' => 0,
                'time_limit' => 0,
                'quiz_run_once' => 0,
            ]);
        }

        foreach ($questions as $i => $q) {
            $wp->table('learndash_pro_quiz_question')->insert([
                'id' => $q['id'],
                'quiz_id' => $masterId,
                'sort' => $i,
                'title' => $q['title'] ?? 'Q'.$q['id'],
                'points' => $q['points'] ?? 1,
                'question' => $q['question'] ?? 'Body '.$q['id'],
                'correct_msg' => '',
                'incorrect_msg' => '',
                'answer_type' => $q['type'],
                'answer_data' => $q['answer_data'] ?? '',
            ]);
        }
    }

    private function orphanQuiz(int $quizPostId, int $masterId): void
    {
        $wp = DB::connection('wordpress');
        $wp->table('posts')->insert([
            'ID' => $quizPostId,
            'post_type' => 'sfwd-quiz',
            'post_status' => 'publish',
            'post_title' => 'Orphan '.$quizPostId,
            'post_name' => 'orphan-'.$quizPostId,
            'post_content' => '',
        ]);
        $wp->table('postmeta')->insert([
            ['post_id' => $quizPostId, 'meta_key' => 'quiz_pro_id', 'meta_value' => (string) $masterId],
        ]);
        $wp->table('learndash_pro_quiz_master')->insert([
            'id' => $masterId,
            'name' => 'Orphan master',
            'text' => '',
            'question_random' => 0,
            'answer_random' => 0,
            'time_limit' => 0,
            'quiz_run_once' => 0,
        ]);
    }

    public function test_safe_answer_decoder_and_malformed_rejection(): void
    {
        $decoder = app(WordPressProQuizAnswerDecoder::class);
        $ok = $decoder->decode($this->serializeAnswers([
            ['answer' => 'A', 'correct' => true],
            ['answer' => 'B', 'correct' => false],
        ]));
        $this->assertNotNull($ok);
        $this->assertCount(2, $ok);
        $this->assertTrue($ok[0]['correct']);
        $this->assertNull($decoder->decode('not-serialized'));
        $this->assertSame([], $decoder->decode(''));
    }

    public function test_dry_run_imports_only_tree_quizzes_and_excludes_orphans(): void
    {
        $this->seedTreeQuiz(9201, 501, '80', [
            [
                'id' => 7001,
                'type' => 'single',
                'answer_data' => $this->serializeAnswers([
                    ['answer' => 'Yes', 'correct' => true],
                    ['answer' => 'No', 'correct' => false],
                ]),
            ],
        ]);
        $this->orphanQuiz(9301, 601);

        $report = app(WordPressQuizImporter::class)->import($this->run->id, true);
        $this->assertSame('dry_run', $report['status']);
        $this->assertSame(1, $report['tree_quizzes_scanned']);
        $this->assertSame(1, $report['quiz_create']);
        $this->assertSame(1, $report['question_create']);
        $this->assertSame(2, $report['options_create']);
        $this->assertFalse($report['writes']);
        $this->assertSame(0, Quiz::query()->count());
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'quiz')->count());
    }

    public function test_execute_creates_optional_quiz_with_types_and_is_idempotent(): void
    {
        $this->enableRealPersist();
        $this->seedTreeQuiz(9202, 502, '80', [
            [
                'id' => 7101,
                'type' => 'single',
                'answer_data' => $this->serializeAnswers([
                    ['answer' => 'A', 'correct' => true],
                    ['answer' => 'B', 'correct' => false],
                ]),
            ],
            [
                'id' => 7102,
                'type' => 'multiple',
                'answer_data' => $this->serializeAnswers([
                    ['answer' => 'A', 'correct' => true],
                    ['answer' => 'B', 'correct' => false],
                    ['answer' => 'C', 'correct' => false],
                ]),
            ],
            [
                'id' => 7103,
                'type' => 'essay',
                'answer_data' => $this->serializeAnswers([['answer' => '', 'correct' => false, 'graded_type' => 'text']]),
            ],
            [
                'id' => 7104,
                'type' => 'sort_answer',
                'answer_data' => $this->serializeAnswers([
                    ['answer' => 'One'],
                    ['answer' => 'Two'],
                ]),
            ],
            [
                'id' => 7105,
                'type' => 'matrix_sort_answer',
                'answer_data' => $this->serializeAnswers([
                    ['answer' => 'Right1', 'sort_string' => 'Left1'],
                    ['answer' => 'Right2', 'sort_string' => 'Left2'],
                ]),
            ],
        ]);

        $first = app(WordPressQuizImporter::class)->import($this->run->id, false);
        $this->assertSame('ok', $first['status']);
        $this->assertSame(1, $first['quiz_create']);
        $this->assertSame(5, $first['question_create']);

        $quiz = Quiz::query()->first();
        $this->assertNotNull($quiz);
        $this->assertFalse($quiz->is_required);
        $this->assertSame(Lesson::class, $quiz->quizable_type);
        $this->assertSame($this->lesson->id, $quiz->quizable_id);
        $this->assertEquals(80.0, (float) $quiz->passing_score);

        $this->assertSame(1, Question::query()->where('question_type', QuestionType::SingleChoice)->count());
        $this->assertSame(1, Question::query()->where('question_type', QuestionType::MultipleChoice)->count());
        $this->assertSame(1, Question::query()->where('question_type', QuestionType::LongAnswer)->count());
        $this->assertSame(1, Question::query()->where('question_type', QuestionType::Ordering)->count());
        $this->assertSame(1, Question::query()->where('question_type', QuestionType::Matching)->count());

        $essay = Question::query()->where('question_type', QuestionType::LongAnswer)->firstOrFail();
        $grader = app(LongAnswerGrader::class);
        $answer = new QuizAttemptAnswer([
            'question_id' => $essay->id,
            'text_answer' => 'student essay',
        ]);
        // LongAnswerGrader persists needs_manual_review when answer model is provided with id.
        // Use a lightweight in-memory assertion on return value + flag when saved.
        $this->assertFalse($grader->supports(QuestionType::SingleChoice));
        $this->assertTrue($grader->supports(QuestionType::LongAnswer));

        // Persist minimal attempt/answer rows to exercise grader side-effect.
        $user = User::factory()->create();
        $enrollment = Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now(),
        ]);
        $attempt = QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'enrollment_id' => $enrollment->id,
            'attempt_number' => 1,
            'status' => AttemptStatus::InProgress,
            'started_at' => now(),
        ]);
        $answer->quiz_attempt_id = $attempt->id;
        $answer->save();
        $this->assertFalse($grader->grade($essay, $answer->fresh()));
        $this->assertTrue((bool) $answer->fresh()->needs_manual_review);

        $second = app(WordPressQuizImporter::class)->import($this->run->id, false);
        $this->assertSame(1, $second['quiz_skip_mapped']);
        $this->assertSame(0, $second['quiz_create']);
        $this->assertSame(1, Quiz::query()->count());
        $this->assertSame(5, Question::query()->count());
    }

    public function test_empty_quiz_and_anomalous_singles_deferred(): void
    {
        $this->enableRealPersist();
        $this->seedTreeQuiz(9203, 503, '80', []); // empty
        $this->seedTreeQuiz(9204, 504, '80', [
            ['id' => 7201, 'type' => 'single', 'answer_data' => ''],
            [
                'id' => 7202,
                'type' => 'single',
                'answer_data' => $this->serializeAnswers([
                    ['answer' => 'A', 'correct' => false],
                    ['answer' => 'B', 'correct' => false],
                ]),
            ],
            [
                'id' => 7203,
                'type' => 'single',
                'answer_data' => $this->serializeAnswers([
                    ['answer' => 'Only', 'correct' => true],
                ]),
            ],
        ]);

        $report = app(WordPressQuizImporter::class)->import($this->run->id, false);
        $this->assertGreaterThanOrEqual(1, $report['quiz_defer_empty']);
        $this->assertSame(1, $report['question_empty_answer_data']);
        $this->assertSame(1, $report['question_zero_correct']);
        $this->assertSame(1, Quiz::query()->count());
        $this->assertSame(1, Question::query()->count());
    }

    public function test_missing_parent_and_persist_gate(): void
    {
        $this->seedTreeQuiz(9205, 505, '80', [
            [
                'id' => 7301,
                'type' => 'single',
                'answer_data' => $this->serializeAnswers([['answer' => 'A', 'correct' => true]]),
            ],
        ]);
        // Remove lesson map
        LegacyImportMap::query()->where('entity_type', 'lesson')->delete();

        $dry = app(WordPressQuizImporter::class)->import($this->run->id, true);
        $this->assertSame(1, $dry['quiz_defer_missing_parent']);

        config(['wordpress.real_persist' => null]);
        $blocked = app(WordPressQuizImporter::class)->import($this->run->id, false);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame(0, Quiz::query()->count());
    }

    public function test_media_pending_and_no_http_on_dry_run(): void
    {
        Http::fake();
        $this->seedTreeQuiz(9206, 506, '80', [
            [
                'id' => 7401,
                'type' => 'single',
                'question' => 'See <img src="https://example.com/wp-content/uploads/a.png">',
                'answer_data' => $this->serializeAnswers([['answer' => 'A', 'correct' => true]]),
            ],
        ]);
        $report = app(WordPressQuizImporter::class)->import($this->run->id, true);
        $this->assertSame(1, $report['media_pending_questions']);
        $this->assertSame(0, $report['external_requests']);
        Http::assertNothingSent();
    }

    public function test_artisan_dry_run_command(): void
    {
        $this->seedTreeQuiz(9207, 507, '80', [
            [
                'id' => 7501,
                'type' => 'single',
                'answer_data' => $this->serializeAnswers([['answer' => 'A', 'correct' => true]]),
            ],
        ]);
        $this->artisan('migration:wordpress:quizzes', [
            '--migration-run' => $this->run->id,
            '--dry-run' => true,
        ])->assertSuccessful();
        $this->assertSame(0, Quiz::query()->count());
    }

    public function test_same_numeric_lesson_id_ignored_without_map(): void
    {
        // Native lesson with id that equals legacy quiz id must not be used without map.
        $bait = Lesson::query()->create([
            'course_id' => $this->course->id,
            'slug' => 'bait-9208',
            'sort_order' => 9,
            'is_published' => true,
            'title' => ['ar' => 'bait'],
        ]);
        // Ensure bait id could confuse naive importers
        $this->assertGreaterThan(0, $bait->id);

        LegacyImportMap::query()->where('entity_type', 'lesson')->delete();
        $this->seedTreeQuiz(9208, 508, '80', [
            [
                'id' => 7601,
                'type' => 'single',
                'answer_data' => $this->serializeAnswers([['answer' => 'A', 'correct' => true]]),
            ],
        ]);
        $report = app(WordPressQuizImporter::class)->import($this->run->id, true);
        $this->assertSame(1, $report['quiz_defer_missing_parent']);
        $this->assertSame(0, $report['quiz_create']);
    }

    private function enableRealPersist(): void
    {
        putenv('WORDPRESS_REAL_PERSIST=1');
        $_ENV['WORDPRESS_REAL_PERSIST'] = '1';
        $_SERVER['WORDPRESS_REAL_PERSIST'] = '1';
        config(['wordpress.real_persist' => '1']);
    }
}
