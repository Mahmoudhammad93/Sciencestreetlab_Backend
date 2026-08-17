<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptQuestion;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuestionBankSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_quiz_freezes_questions_across_refresh(): void
    {
        [$user, $quiz] = $this->seedGeneratedQuiz(10);

        Sanctum::actingAs($user);

        $start = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated();
        $attemptId = $start->json('data.id');
        $firstIds = collect($start->json('data.questions'))->pluck('id')->sort()->values()->all();

        $this->assertCount(5, $firstIds);
        $this->assertDatabaseCount('quiz_attempt_questions', 5);

        $resume = $this->getJson("/api/v1/attempts/{$attemptId}")->assertOk();
        $secondIds = collect($resume->json('data.questions'))->pluck('id')->sort()->values()->all();

        $this->assertSame($firstIds, $secondIds);
        $this->assertSame(5, QuizAttemptQuestion::query()->where('quiz_attempt_id', $attemptId)->count());
    }

    public function test_difficulty_distribution_and_insufficient_questions(): void
    {
        [$user, $quiz, $bank] = $this->seedGeneratedQuiz(2, withReturnBank: true);

        // Only 2 easy questions exist — requesting 3 easy should fail
        $quiz->update([
            'selection_config' => ['difficulty' => ['easy' => 3, 'medium' => 0, 'hard' => 0]],
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_QUESTIONS');

        $quiz->update([
            'selection_config' => ['difficulty' => ['easy' => 2, 'medium' => 0, 'hard' => 0]],
        ]);

        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated();
    }

    public function test_student_payload_hides_correct_answers(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = \App\Modules\Catalog\Infrastructure\Persistence\Models\Product::query()
            ->where('sku', 'SS-MICRO-001')->firstOrFail();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id]);
        $orderId = $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'S', 'email' => $user->email, 'phone' => '01000000000',
                'city' => 'Cairo', 'country' => 'EG',
            ],
            'shipping_address' => ['city' => 'Cairo', 'country' => 'EG'],
        ])->json('data.id');
        $paymentId = $this->postJson("/api/v1/checkout/{$orderId}/pay")->json('data.payment_id');
        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete");

        $quiz = Quiz::query()->where('quizable_type', Lesson::class)->firstOrFail();
        $payload = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated()->json();

        $json = json_encode($payload);
        $this->assertStringNotContainsString('is_correct', $json);
        $this->assertStringNotContainsString('answer_key', $json);
    }

    public function test_short_answer_and_numeric_graders(): void
    {
        [$user, $quiz, $bank, $lesson] = $this->seedCourseShell();

        $short = Question::query()->create([
            'question_bank_id' => $bank->id,
            'quiz_id' => null,
            'question_type' => QuestionType::ShortAnswer,
            'difficulty' => QuestionDifficulty::Easy,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'body' => ['en' => 'Capital of Egypt?', 'ar' => 'عاصمة مصر؟'],
            'answer_key' => ['accepted' => ['Cairo', 'cairo']],
        ]);

        $numeric = Question::query()->create([
            'question_bank_id' => $bank->id,
            'question_type' => QuestionType::Numeric,
            'difficulty' => QuestionDifficulty::Medium,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'body' => ['en' => '2+2?', 'ar' => '2+2؟'],
            'answer_key' => ['value' => 4, 'tolerance' => 0],
        ]);

        $quiz->update([
            'selection_mode' => QuizSelectionMode::Generated,
            'selection_config' => ['total_questions' => 2],
        ]);
        $quiz->questionBanks()->sync([$bank->id]);

        Sanctum::actingAs($user);
        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');

        $this->postJson("/api/v1/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $short->id, 'text_answer' => '  Cairo  '],
                ['question_id' => $numeric->id, 'numeric_answer' => 4],
            ],
        ])->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.correct_answers', 2);
    }

    public function test_inactive_questions_are_excluded(): void
    {
        [$user, $quiz, $bank] = $this->seedGeneratedQuiz(5, withReturnBank: true);

        Question::query()->where('question_bank_id', $bank->id)->update(['status' => QuestionStatus::Draft]);

        // republish only 1
        Question::query()->where('question_bank_id', $bank->id)->limit(1)->update(['status' => QuestionStatus::Published]);

        $quiz->update(['selection_config' => ['total_questions' => 3]]);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_QUESTIONS');
    }

    public function test_ordering_grader(): void
    {
        [$user, $quiz, $bank] = $this->seedCourseShell();

        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'question_type' => QuestionType::Ordering,
            'difficulty' => QuestionDifficulty::Easy,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'body' => ['en' => 'Order steps', 'ar' => 'رتب'],
        ]);

        $o1 = QuestionOption::query()->create([
            'question_id' => $question->id, 'sort_order' => 1, 'is_correct' => false,
            'label' => ['en' => 'First', 'ar' => '1'],
        ]);
        $o2 = QuestionOption::query()->create([
            'question_id' => $question->id, 'sort_order' => 2, 'is_correct' => false,
            'label' => ['en' => 'Second', 'ar' => '2'],
        ]);

        $quiz->update([
            'selection_mode' => QuizSelectionMode::Generated,
            'selection_config' => ['total_questions' => 1],
        ]);
        $quiz->questionBanks()->sync([$bank->id]);

        Sanctum::actingAs($user);
        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');

        $this->postJson("/api/v1/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'ordering_answer' => [$o1->id, $o2->id]],
            ],
        ])->assertOk()->assertJsonPath('data.passed', true);
    }

    /**
     * @return array{0: User, 1: Quiz, 2?: QuestionBank}
     */
    private function seedGeneratedQuiz(int $questionCount, bool $withReturnBank = false): array
    {
        [$user, $quiz, $bank] = $this->seedCourseShell();

        for ($i = 1; $i <= $questionCount; $i++) {
            $q = Question::query()->create([
                'question_bank_id' => $bank->id,
                'question_type' => QuestionType::SingleChoice,
                'difficulty' => QuestionDifficulty::Easy,
                'status' => QuestionStatus::Published,
                'points' => 1,
                'sort_order' => $i,
                'body' => ['en' => "Q{$i}", 'ar' => "س{$i}"],
            ]);
            QuestionOption::query()->create([
                'question_id' => $q->id,
                'is_correct' => true,
                'sort_order' => 1,
                'label' => ['en' => 'Yes', 'ar' => 'نعم'],
            ]);
            QuestionOption::query()->create([
                'question_id' => $q->id,
                'is_correct' => false,
                'sort_order' => 2,
                'label' => ['en' => 'No', 'ar' => 'لا'],
            ]);
        }

        $quiz->update([
            'selection_mode' => QuizSelectionMode::Generated,
            'selection_config' => ['total_questions' => min(5, $questionCount)],
        ]);
        $quiz->questionBanks()->sync([$bank->id]);

        return $withReturnBank ? [$user, $quiz, $bank] : [$user, $quiz];
    }

    /**
     * @return array{0: User, 1: Quiz, 2: QuestionBank, 3: Lesson}
     */
    private function seedCourseShell(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'qb-course-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'QB Course', 'ar' => 'كورس'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-1',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
        ]);
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);
        $bank = QuestionBank::query()->create([
            'lesson_id' => $lesson->id,
            'status' => 'active',
            'title' => ['en' => 'Bank', 'ar' => 'بنك'],
        ]);
        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'passing_score' => 70,
            'is_required' => false,
            'selection_mode' => QuizSelectionMode::Generated,
            'title' => ['en' => 'Generated', 'ar' => 'مولّد'],
        ]);

        return [$user, $quiz, $bank, $lesson];
    }
}
