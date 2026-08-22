<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuizAttemptDurationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Course $course;
    private Lesson $lesson;
    private Enrollment $enrollment;
    private QuestionBank $bank;
    private Quiz $quiz;
    private Question $question;
    private QuestionOption $correctOption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test normal quiz submission with positive duration
     */
    public function test_quiz_submission_calculates_positive_duration(): void
    {
        Sanctum::actingAs($this->user);

        $startTime = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startTime);

        $startResponse = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId = $startResponse->json('data.attempt_id');

        $attempt = QuizAttempt::query()->find($attemptId);
        $this->assertNotNull($attempt->started_at);
        $this->assertNull($attempt->time_spent_seconds);

        // Advance time by 30 seconds
        Carbon::setTestNow($startTime->clone()->addSeconds(30));

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $this->question->id,
            'answer' => ['option_id' => $this->correctOption->id],
        ])->assertOk();

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/submit")->assertOk();

        $attempt->refresh();
        $this->assertNotNull($attempt->time_spent_seconds);
        $this->assertSame(30, $attempt->time_spent_seconds);
        $this->assertIsInt($attempt->time_spent_seconds);
    }

    /**
     * Test that duration is stored as integer, not float
     */
    public function test_quiz_duration_is_stored_as_integer(): void
    {
        Sanctum::actingAs($this->user);

        $startTime = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startTime);

        $startResponse = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId = $startResponse->json('data.attempt_id');

        Carbon::setTestNow($startTime->clone()->addSeconds(45));

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $this->question->id,
            'answer' => ['option_id' => $this->correctOption->id],
        ])->assertOk();

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/submit")->assertOk();

        $attempt = QuizAttempt::query()->find($attemptId);
        $this->assertIsInt($attempt->time_spent_seconds);
        $this->assertSame(45, $attempt->time_spent_seconds);
        $this->assertEquals($attempt->time_spent_seconds, (int) $attempt->time_spent_seconds);
    }

    /**
     * Test zero duration when attempting immediately after start
     */
    public function test_zero_duration_is_valid(): void
    {
        Sanctum::actingAs($this->user);

        $startTime = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startTime);

        $startResponse = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId = $startResponse->json('data.attempt_id');

        // Submit at exactly the same time (no time advance)
        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/submit")->assertOk();

        $attempt = QuizAttempt::query()->find($attemptId);
        $this->assertSame(0, $attempt->time_spent_seconds);
    }

    /**
     * Test that duration is never negative, even when started_at is in the future
     * This is the critical edge case that caused the original bug
     */
    public function test_future_started_at_results_in_zero_duration(): void
    {
        Sanctum::actingAs($this->user);

        $currentTime = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($currentTime);

        // Manually create an attempt with started_at in the future
        $futureTime = $currentTime->clone()->addMinutes(30);
        $attempt = QuizAttempt::query()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'enrollment_id' => $this->enrollment->id,
            'attempt_number' => 1,
            'status' => AttemptStatus::InProgress,
            'started_at' => $futureTime,
        ]);

        // Freeze the questions for this attempt (required by the application)
        QuizAttemptQuestion::query()->create([
            'quiz_attempt_id' => $attempt->id,
            'question_id' => $this->question->id,
            'sort_order' => 1,
        ]);

        // Submit at current time (which is before started_at)
        $this->postJson("/api/v1/quiz-attempts/{$attempt->id}/submit")->assertOk();

        $attempt->refresh();
        // Duration must be 0, never negative
        $this->assertSame(0, $attempt->time_spent_seconds);
    }

    /**
     * Test exact 90-second duration
     */
    public function test_quiz_duration_90_seconds(): void
    {
        Sanctum::actingAs($this->user);

        $startTime = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startTime);

        $startResponse = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId = $startResponse->json('data.attempt_id');

        Carbon::setTestNow($startTime->clone()->addSeconds(90));

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $this->question->id,
            'answer' => ['option_id' => $this->correctOption->id],
        ])->assertOk();

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/submit")->assertOk();

        $attempt = QuizAttempt::query()->find($attemptId);
        $this->assertSame(90, $attempt->time_spent_seconds);
    }

    /**
     * Test that score calculation still works correctly with duration fix
     */
    public function test_score_calculation_not_affected_by_duration_fix(): void
    {
        Sanctum::actingAs($this->user);

        $startTime = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startTime);

        $startResponse = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId = $startResponse->json('data.attempt_id');

        Carbon::setTestNow($startTime->clone()->addSeconds(30));

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $this->question->id,
            'answer' => ['option_id' => $this->correctOption->id],
        ])->assertOk();

        $submitResponse = $this->postJson("/api/v1/quiz-attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.earned_points', 1);

        $attempt = QuizAttempt::query()->find($attemptId);
        $this->assertNotNull($attempt->time_spent_seconds);
        $this->assertSame(30, $attempt->time_spent_seconds);
        $this->assertEquals(1, $attempt->score);
        $this->assertEquals(100, $attempt->percentage);
    }

    /**
     * Test multiple attempts each have deterministic different durations
     */
    public function test_multiple_attempts_each_have_correct_duration(): void
    {
        Sanctum::actingAs($this->user);

        $startBaseTime = Carbon::parse('2026-01-15 10:00:00');

        // First attempt: 30 seconds
        Carbon::setTestNow($startBaseTime);

        $startResponse1 = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId1 = $startResponse1->json('data.attempt_id');

        Carbon::setTestNow($startBaseTime->clone()->addSeconds(30));

        $this->postJson("/api/v1/quiz-attempts/{$attemptId1}/answers", [
            'question_id' => $this->question->id,
            'answer' => ['option_id' => $this->correctOption->id],
        ])->assertOk();

        $this->postJson("/api/v1/quiz-attempts/{$attemptId1}/submit")->assertOk();

        $attempt1 = QuizAttempt::query()->find($attemptId1);
        $this->assertSame(30, $attempt1->time_spent_seconds);

        // Second attempt: 90 seconds (starting from a later base time)
        $startBaseTime2 = Carbon::parse('2026-01-15 11:00:00');
        Carbon::setTestNow($startBaseTime2);

        $startResponse2 = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId2 = $startResponse2->json('data.attempt_id');

        Carbon::setTestNow($startBaseTime2->clone()->addSeconds(90));

        $this->postJson("/api/v1/quiz-attempts/{$attemptId2}/answers", [
            'question_id' => $this->question->id,
            'answer' => ['option_id' => $this->correctOption->id],
        ])->assertOk();

        $this->postJson("/api/v1/quiz-attempts/{$attemptId2}/submit")->assertOk();

        $attempt2 = QuizAttempt::query()->find($attemptId2);
        $this->assertSame(90, $attempt2->time_spent_seconds);
    }

    /**
     * Test that attempt data persists correctly without database errors
     */
    public function test_attempt_data_persists_correctly_to_database(): void
    {
        Sanctum::actingAs($this->user);

        $startTime = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startTime);

        $startResponse = $this->postJson("/api/v1/quizzes/{$this->quiz->id}/attempts")
            ->assertCreated();
        $attemptId = $startResponse->json('data.attempt_id');

        Carbon::setTestNow($startTime->clone()->addSeconds(15));

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $this->question->id,
            'answer' => ['option_id' => $this->correctOption->id],
        ])->assertOk();

        // This should not throw SQLSTATE[22003] error (numeric value out of range)
        $submitResponse = $this->postJson("/api/v1/quiz-attempts/{$attemptId}/submit")
            ->assertOk();

        // Verify data was persisted correctly
        $attempt = QuizAttempt::query()->find($attemptId);
        $this->assertNotNull($attempt->time_spent_seconds);
        $this->assertIsInt($attempt->time_spent_seconds);
        $this->assertSame(15, $attempt->time_spent_seconds);
    }

    /**
     * Seed the test fixture
     */
    private function seedFixture(): void
    {
        $this->user = User::factory()->create();
        $this->course = Course::query()->create([
            'slug' => 'duration-test-' . uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Duration Test Course', 'ar' => 'اختبار المدة'],
        ]);
        $this->lesson = Lesson::query()->create([
            'course_id' => $this->course->id,
            'slug' => 'duration-lesson',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Duration Lesson', 'ar' => 'درس المدة'],
        ]);
        $this->enrollment = Enrollment::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);
        $this->bank = QuestionBank::query()->create([
            'lesson_id' => $this->lesson->id,
            'status' => QuestionBankStatus::Active,
            'title' => ['en' => 'Bank', 'ar' => 'بنك'],
            'description' => ['en' => 'Desc', 'ar' => 'وصف'],
        ]);
        $this->quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $this->lesson->id,
            'passing_score' => 50,
            'time_limit_seconds' => 600,
            'is_required' => false,
            'selection_mode' => QuizSelectionMode::Fixed,
            'title' => ['en' => 'Duration Quiz', 'ar' => 'اختبار المدة'],
        ]);
        $this->quiz->questionBanks()->sync([$this->bank->id]);

        $this->question = Question::query()->create([
            'question_bank_id' => $this->bank->id,
            'quiz_id' => $this->quiz->id,
            'question_type' => QuestionType::SingleChoice,
            'difficulty' => QuestionDifficulty::Easy,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'sort_order' => 1,
            'body' => ['en' => '2+2?', 'ar' => '2+2؟'],
            'explanation' => ['en' => 'Four', 'ar' => 'أربعة'],
            'answer_key' => ['hidden' => true],
        ]);
        $this->correctOption = QuestionOption::query()->create([
            'question_id' => $this->question->id,
            'is_correct' => true,
            'sort_order' => 1,
            'label' => ['en' => '4', 'ar' => '4'],
        ]);
        QuestionOption::query()->create([
            'question_id' => $this->question->id,
            'is_correct' => false,
            'sort_order' => 2,
            'label' => ['en' => '5', 'ar' => '5'],
        ]);
    }
}
