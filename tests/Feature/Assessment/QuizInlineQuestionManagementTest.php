<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Filament\Resources\QuizResource\Pages\EditQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\QuestionsRelationManager;
use App\Models\User;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Http\Resources\StudentQuestionResource;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptQuestion;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class QuizInlineQuestionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_quiz_shows_zero_questions_in_relation_title(): void
    {
        app()->setLocale('ar');
        $quiz = $this->makeQuiz('وقت فارغ', 'Empty');

        $this->assertSame(0, $quiz->questions()->count());
        $this->assertSame(
            'أسئلة الاختبار (0)',
            QuestionsRelationManager::getTitle($quiz, EditQuiz::class)
        );
    }

    public function test_single_assigned_question_is_displayed(): void
    {
        app()->setLocale('ar');
        $quiz = $this->makeQuiz('اختبار واحد', 'One');
        $q = $this->makeQuestion($quiz, 'سؤال واحد؟', QuestionType::SingleChoice, 1, 1);
        $this->makeChoiceOptions($q, oneCorrect: true);

        $other = $this->makeQuiz('آخر', 'Other');
        $this->makeQuestion($other, 'سؤال غير مرتبط', QuestionType::LongAnswer, 1, 1);

        $ids = $quiz->questions()->pluck('id')->all();
        $this->assertSame([$q->id], $ids);
        $this->assertSame(1, $quiz->questions()->count());
        $this->assertSame(
            'أسئلة الاختبار (1)',
            QuestionsRelationManager::getTitle($quiz->fresh(), EditQuiz::class)
        );
    }

    public function test_lesson_183_style_quiz_shows_exactly_three_imported_questions(): void
    {
        app()->setLocale('ar');
        $quiz = $this->makeQuiz('وقت التحدي', 'Challenge Time');
        $q1 = $this->makeQuestion($quiz, 'ما هي الحشرة ...؟', QuestionType::MultipleChoice, 1, 1);
        $q2 = $this->makeQuestion($quiz, 'اختر الإجابة الصحيحة', QuestionType::SingleChoice, 1, 2);
        $q3 = $this->makeQuestion($quiz, 'اكتب إجابتك', QuestionType::LongAnswer, 2, 3);
        $this->makeChoiceOptions($q1, oneCorrect: false);
        $this->makeChoiceOptions($q2, oneCorrect: true);

        $unrelated = $this->makeQuiz('غير مرتبط', 'Unrelated');
        $this->makeQuestion($unrelated, 'يجب ألا يظهر', QuestionType::SingleChoice, 1, 1);

        foreach ([$q1, $q2, $q3] as $q) {
            DB::table('legacy_import_maps')->insert([
                'source' => 'wordpress',
                'entity_type' => 'question',
                'legacy_id' => 'wp-q-'.$q->id,
                'local_id' => $q->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $assigned = $quiz->questions()->orderBy('sort_order')->get();
        $this->assertCount(3, $assigned);
        $this->assertSame([$q1->id, $q2->id, $q3->id], $assigned->pluck('id')->all());
        $this->assertFalse($assigned->contains('id', $unrelated->questions()->first()?->id));
        $this->assertSame(
            'أسئلة الاختبار (3)',
            QuestionsRelationManager::getTitle($quiz->fresh(), EditQuiz::class)
        );

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(QuestionsRelationManager::class, [
                'ownerRecord' => $quiz,
                'pageClass' => EditQuiz::class,
            ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($assigned)
            ->assertCanNotSeeTableRecords($unrelated->questions()->get());
    }

    public function test_edit_preserves_question_id_and_legacy_map(): void
    {
        $quiz = $this->makeQuiz('تحرير', 'Edit');
        $question = $this->makeQuestion($quiz, 'نص قديم', QuestionType::SingleChoice, 1, 1);
        $this->makeChoiceOptions($question, oneCorrect: true);

        DB::table('legacy_import_maps')->insert([
            'source' => 'wordpress',
            'entity_type' => 'question',
            'legacy_id' => 'legacy-q-42',
            'local_id' => $question->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $originalId = $question->id;
        $question->update([
            'body' => ['ar' => 'نص محدّث', 'en' => 'Updated'],
            'points' => 2,
        ]);

        $this->assertDatabaseHas('questions', ['id' => $originalId, 'quiz_id' => $quiz->id]);
        $this->assertSame(1, Question::query()->where('quiz_id', $quiz->id)->count());
        $this->assertDatabaseHas('legacy_import_maps', [
            'entity_type' => 'question',
            'legacy_id' => 'legacy-q-42',
            'local_id' => $originalId,
        ]);
    }

    public function test_add_question_assigns_to_current_quiz_automatically(): void
    {
        $quiz = $this->makeQuiz('إضافة', 'Add');

        $created = $quiz->questions()->create([
            'question_type' => QuestionType::LongAnswer,
            'difficulty' => QuestionDifficulty::Medium,
            'status' => QuestionStatus::Published,
            'points' => 3,
            'sort_order' => 1,
            'body' => ['ar' => 'سؤال جديد', 'en' => 'New'],
        ]);

        $this->assertSame($quiz->id, $created->quiz_id);
        $this->assertSame(1, $quiz->questions()->count());
    }

    public function test_single_choice_validation_requires_exactly_one_correct(): void
    {
        $this->expectException(ValidationException::class);
        QuestionsRelationManager::assertChoiceCorrectness([
            'question_type' => QuestionType::SingleChoice->value,
            'options' => [
                ['label' => ['ar' => 'أ'], 'is_correct' => false],
                ['label' => ['ar' => 'ب'], 'is_correct' => false],
            ],
        ]);
    }

    public function test_multiple_choice_validation_requires_at_least_one_correct(): void
    {
        $this->expectException(ValidationException::class);
        QuestionsRelationManager::assertChoiceCorrectness([
            'question_type' => QuestionType::MultipleChoice->value,
            'options' => [
                ['label' => ['ar' => 'أ'], 'is_correct' => false],
                ['label' => ['ar' => 'ب'], 'is_correct' => false],
            ],
        ]);
    }

    public function test_long_answer_does_not_require_options(): void
    {
        QuestionsRelationManager::assertChoiceCorrectness([
            'question_type' => QuestionType::LongAnswer->value,
            'options' => [],
        ]);
        $this->assertTrue(true);
    }

    public function test_matching_persistence_compatible_with_grader_schema(): void
    {
        $quiz = $this->makeQuiz('توصيل', 'Matching');
        $q = $this->makeQuestion($quiz, 'وصّل', QuestionType::Matching, 2, 1);
        QuestionOption::query()->create([
            'question_id' => $q->id,
            'is_correct' => false,
            'sort_order' => 1,
            'label' => ['ar' => 'يسار 1', 'en' => 'L1'],
            'meta' => ['side' => 'left', 'match_key' => 'm0'],
        ]);
        QuestionOption::query()->create([
            'question_id' => $q->id,
            'is_correct' => false,
            'sort_order' => 2,
            'label' => ['ar' => 'يمين 1', 'en' => 'R1'],
            'meta' => ['side' => 'right', 'match_key' => 'm0'],
        ]);

        QuestionsRelationManager::assertChoiceCorrectness([
            'question_type' => QuestionType::Matching->value,
            'options' => $q->fresh()->options->map(fn ($o) => [
                'label' => $o->label,
                'is_correct' => false,
                'meta' => $o->meta,
            ])->all(),
        ]);

        $q->load('options');
        $left = $q->options->firstWhere(fn ($o) => ($o->meta['side'] ?? null) === 'left');
        $right = $q->options->firstWhere(fn ($o) => ($o->meta['side'] ?? null) === 'right');
        $this->assertSame('m0', $left?->meta['match_key'] ?? null);
        $this->assertSame('m0', $right?->meta['match_key'] ?? null);
    }

    public function test_ordering_persistence_uses_sort_order(): void
    {
        $quiz = $this->makeQuiz('ترتيب', 'Ordering');
        $q = $this->makeQuestion($quiz, 'رتّب', QuestionType::Ordering, 2, 1);
        $a = QuestionOption::query()->create([
            'question_id' => $q->id,
            'is_correct' => false,
            'sort_order' => 0,
            'label' => ['ar' => 'أولاً', 'en' => 'First'],
        ]);
        $b = QuestionOption::query()->create([
            'question_id' => $q->id,
            'is_correct' => false,
            'sort_order' => 1,
            'label' => ['ar' => 'ثانياً', 'en' => 'Second'],
        ]);

        $ordered = $q->options()->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame([$a->id, $b->id], $ordered);

        $b->update(['sort_order' => 0]);
        $a->update(['sort_order' => 1]);
        $reordered = $q->options()->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $reordered);
    }

    public function test_question_reorder_persists_sort_order(): void
    {
        $quiz = $this->makeQuiz('إعادة ترتيب', 'Reorder');
        $q1 = $this->makeQuestion($quiz, 'أ', QuestionType::LongAnswer, 1, 0);
        $q2 = $this->makeQuestion($quiz, 'ب', QuestionType::LongAnswer, 1, 1);
        $q3 = $this->makeQuestion($quiz, 'ج', QuestionType::LongAnswer, 1, 2);

        $q1->update(['sort_order' => 2]);
        $q2->update(['sort_order' => 0]);
        $q3->update(['sort_order' => 1]);

        $this->assertSame(
            [$q2->id, $q3->id, $q1->id],
            $quiz->questions()->orderBy('sort_order')->pluck('id')->all()
        );
    }

    public function test_question_with_historical_answers_cannot_be_destructively_removed(): void
    {
        $quiz = $this->makeQuiz('تاريخي', 'History');
        $question = $this->makeQuestion($quiz, 'محمي', QuestionType::LongAnswer, 1, 1);
        $user = User::factory()->create();
        $attempt = QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'attempt_number' => 1,
            'status' => AttemptStatus::Submitted,
            'score' => 0,
            'max_score' => 1,
            'percentage' => 0,
            'passed' => false,
            'started_at' => now(),
            'submitted_at' => now(),
        ]);
        QuizAttemptAnswer::query()->create([
            'quiz_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'text_answer' => 'إجابة طالب',
            'needs_manual_review' => true,
        ]);
        QuizAttemptQuestion::query()->create([
            'quiz_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'sort_order' => 1,
        ]);

        $this->assertTrue(QuestionsRelationManager::questionHasHistoricalAnswers($question));
    }

    public function test_student_api_strips_correct_and_sensitive_fields(): void
    {
        $quiz = $this->makeQuiz('تسريب', 'Leak');
        $question = $this->makeQuestion($quiz, 'سؤال', QuestionType::Matching, 1, 1);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'is_correct' => true,
            'sort_order' => 1,
            'label' => ['ar' => 'يسار', 'en' => 'L'],
            'meta' => ['side' => 'left', 'match_key' => 'secret', 'is_correct' => true],
        ]);
        $question->load('options');

        $payload = (new StudentQuestionResource($question))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('answer_key', $payload);
        $this->assertArrayNotHasKey('is_correct', $payload['options'][0]);
        $this->assertSame('left', $payload['options'][0]['meta']['side'] ?? null);
        $this->assertArrayNotHasKey('match_key', $payload['options'][0]['meta'] ?? []);
    }

    public function test_quiz_resource_table_counts_questions_from_relationship(): void
    {
        app()->setLocale('ar');
        $quiz = $this->makeQuiz('عدد', 'Count');
        $this->makeQuestion($quiz, '1', QuestionType::LongAnswer, 1, 1);
        $this->makeQuestion($quiz, '2', QuestionType::LongAnswer, 1, 2);

        $listed = Quiz::query()->withCount('questions')->findOrFail($quiz->id);
        $this->assertSame(2, (int) $listed->questions_count);
        $this->assertSame(
            'أسئلة الاختبار (2)',
            QuestionsRelationManager::getTitle($listed, EditQuiz::class)
        );
    }

    public function test_arabic_type_labels_are_user_friendly(): void
    {
        app()->setLocale('ar');
        $this->assertSame('اختيار واحد', QuestionsRelationManager::typeLabel(QuestionType::SingleChoice));
        $this->assertSame('اختيار متعدد', QuestionsRelationManager::typeLabel(QuestionType::MultipleChoice));
        $this->assertSame('إجابة مكتوبة', QuestionsRelationManager::typeLabel(QuestionType::LongAnswer));
        $this->assertSame('توصيل', QuestionsRelationManager::typeLabel(QuestionType::Matching));
        $this->assertSame('ترتيب', QuestionsRelationManager::typeLabel(QuestionType::Ordering));
    }

    private function makeQuiz(string $titleAr, string $titleEn): Quiz
    {
        $course = Course::query()->create([
            'slug' => 'course-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Course', 'ar' => 'دورة'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-'.uniqid(),
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
        ]);

        return Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'passing_score' => 70,
            'is_required' => true,
            'selection_mode' => QuizSelectionMode::Fixed,
            'title' => ['ar' => $titleAr, 'en' => $titleEn],
        ]);
    }

    private function makeQuestion(
        Quiz $quiz,
        string $bodyAr,
        QuestionType $type,
        float $points,
        int $sortOrder,
    ): Question {
        return Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => $type,
            'difficulty' => QuestionDifficulty::Medium,
            'status' => QuestionStatus::Published,
            'points' => $points,
            'sort_order' => $sortOrder,
            'body' => ['ar' => $bodyAr, 'en' => $bodyAr],
        ]);
    }

    private function makeChoiceOptions(Question $question, bool $oneCorrect): void
    {
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'is_correct' => true,
            'sort_order' => 1,
            'label' => ['ar' => 'صحيح', 'en' => 'Correct'],
        ]);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'is_correct' => $oneCorrect ? false : true,
            'sort_order' => 2,
            'label' => ['ar' => 'آخر', 'en' => 'Other'],
        ]);
    }
}
