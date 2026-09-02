<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
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
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuizOfficialScoreApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private Course $course;

    private Lesson $lesson;

    private Enrollment $enrollment;

    private Enrollment $otherEnrollment;

    private Quiz $quiz;

    private Question $question;

    private QuestionOption $correctOption;

    private QuestionOption $wrongOption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    public function test_quiz_show_returns_null_official_fields_when_user_has_no_submitted_attempts(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.official_score', null)
            ->assertJsonPath('data.official_attempt_number', null)
            ->assertJsonPath('data.official_attempt_id', null)
            ->assertJsonPath('data.official_passed', null)
            ->assertJsonPath('data.has_previous_attempt', false);
    }

    public function test_first_successfully_submitted_attempt_becomes_official_on_quiz_show(): void
    {
        Sanctum::actingAs($this->user);

        $attempt = $this->submitAttempt($this->user, $this->enrollment, $this->wrongOption);

        $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.official_attempt_id', $attempt->id)
            ->assertJsonPath('data.official_attempt_number', 1)
            ->assertJsonPath('data.has_previous_attempt', true);

        $this->assertTrue($attempt->fresh()->is_official);
    }

    public function test_quiz_show_returns_official_score(): void
    {
        Sanctum::actingAs($this->user);

        $attempt = $this->submitAttempt($this->user, $this->enrollment, $this->wrongOption);
        $expectedScore = (float) $attempt->fresh()->percentage;

        $response = $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk();

        $this->assertEquals($expectedScore, (float) $response->json('data.official_score'));
    }

    public function test_quiz_show_returns_official_passed(): void
    {
        Sanctum::actingAs($this->user);

        $this->submitAttempt($this->user, $this->enrollment, $this->correctOption);

        $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.official_passed', true);
    }

    public function test_abandoned_first_attempt_does_not_become_official(): void
    {
        Sanctum::actingAs($this->user);

        $service = app(QuizAttemptService::class);
        $abandoned = $service->start($this->user, $this->quiz, $this->enrollment);
        $abandoned->update(['status' => AttemptStatus::Abandoned]);

        $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.official_attempt_id', null)
            ->assertJsonPath('data.has_previous_attempt', false);

        $this->assertFalse($abandoned->fresh()->is_official);
    }

    public function test_second_attempt_becomes_official_when_first_was_abandoned(): void
    {
        Sanctum::actingAs($this->user);

        $service = app(QuizAttemptService::class);
        $attempt1 = $service->start($this->user, $this->quiz, $this->enrollment);
        $attempt1->update(['status' => AttemptStatus::Abandoned]);

        $attempt2 = $this->submitAttempt($this->user, $this->enrollment, $this->correctOption);

        $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.official_attempt_number', 2)
            ->assertJsonPath('data.official_attempt_id', $attempt2->id)
            ->assertJsonPath('data.official_passed', true);
    }

    public function test_higher_retry_score_does_not_replace_official_score(): void
    {
        Sanctum::actingAs($this->user);

        $official = $this->submitAttempt($this->user, $this->enrollment, $this->wrongOption);
        $officialScore = (float) $official->fresh()->percentage;

        $retry = $this->submitAttempt($this->user, $this->enrollment, $this->correctOption);
        $retryScore = (float) $retry->fresh()->percentage;

        $this->assertGreaterThan($officialScore, $retryScore);
        $this->assertFalse($retry->fresh()->is_official);

        $response = $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.official_attempt_number', 1);

        $this->assertEquals($officialScore, (float) $response->json('data.official_score'));
    }

    public function test_lower_retry_score_does_not_replace_official_score(): void
    {
        Sanctum::actingAs($this->user);

        $official = $this->submitAttempt($this->user, $this->enrollment, $this->correctOption);
        $officialScore = (float) $official->fresh()->percentage;

        $retry = $this->submitAttempt($this->user, $this->enrollment, $this->wrongOption);
        $retryScore = (float) $retry->fresh()->percentage;

        $this->assertLessThan($officialScore, $retryScore);

        $response = $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk();

        $this->assertEquals($officialScore, (float) $response->json('data.official_score'));
    }

    public function test_multiple_retries_do_not_change_official_score(): void
    {
        Sanctum::actingAs($this->user);

        $official = $this->submitAttempt($this->user, $this->enrollment, $this->wrongOption);
        $officialScore = (float) $official->fresh()->percentage;

        $this->submitAttempt($this->user, $this->enrollment, $this->correctOption);
        $this->submitAttempt($this->user, $this->enrollment, $this->correctOption);

        $response = $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.official_attempt_number', 1);

        $this->assertEquals($officialScore, (float) $response->json('data.official_score'));
    }

    public function test_different_users_have_independent_official_scores(): void
    {
        $user1Attempt = $this->submitAttempt($this->user, $this->enrollment, $this->wrongOption);
        $user2Attempt = $this->submitAttempt($this->otherUser, $this->otherEnrollment, $this->correctOption);

        Sanctum::actingAs($this->user);
        $user1Response = $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk();
        $user1Response->assertJsonPath('data.official_attempt_id', $user1Attempt->id);
        $this->assertEquals(
            (float) $user1Attempt->fresh()->percentage,
            (float) $user1Response->json('data.official_score'),
        );

        Sanctum::actingAs($this->otherUser);
        $user2Response = $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk();
        $user2Response
            ->assertJsonPath('data.official_attempt_id', $user2Attempt->id)
            ->assertJsonPath('data.official_passed', true);
        $this->assertEquals(
            (float) $user2Attempt->fresh()->percentage,
            (float) $user2Response->json('data.official_score'),
        );
    }

    public function test_quiz_result_api_continues_to_expose_official_and_current_attempt_scores(): void
    {
        Sanctum::actingAs($this->user);

        $official = $this->submitAttempt($this->user, $this->enrollment, $this->wrongOption);
        $officialScore = (float) $official->fresh()->percentage;
        $retry = $this->submitAttempt($this->user, $this->enrollment, $this->correctOption);

        $result = $this->getJson("/api/v1/quiz-attempts/{$retry->id}/result")
            ->assertOk()
            ->assertJsonPath('data.attempt_number', 2)
            ->assertJsonPath('data.is_official', false)
            ->assertJsonPath('data.official_attempt_id', $official->id)
            ->assertJsonPath('data.official_attempt_number', 1);

        $this->assertEquals($officialScore, (float) $result->json('data.official_score'));
        $this->assertEquals(
            (float) $retry->fresh()->percentage,
            (float) $result->json('data.current_attempt_score'),
        );
    }

    public function test_quiz_show_still_returns_existing_quiz_metadata(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/quizzes/{$this->quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->quiz->id)
            ->assertJsonPath('data.max_attempts', 3)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'passing_score',
                    'max_attempts',
                    'official_score',
                    'official_attempt_number',
                    'official_attempt_id',
                    'official_passed',
                    'has_previous_attempt',
                    'interactive_activities',
                ],
            ]);
    }

    private function submitAttempt(User $user, Enrollment $enrollment, QuestionOption $option): \App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt
    {
        $service = app(QuizAttemptService::class);
        $attempt = $service->start($user, $this->quiz, $enrollment);

        return $service->submit($attempt, [
            ['question_id' => $this->question->id, 'selected_option_ids' => [$option->id]],
        ]);
    }

    private function seedFixture(): void
    {
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->course = Course::query()->create([
            'slug' => 'official-score-' . uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Official Score Course', 'ar' => 'دورة'],
        ]);
        $this->lesson = Lesson::query()->create([
            'course_id' => $this->course->id,
            'slug' => 'official-lesson',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
        ]);
        $this->enrollment = Enrollment::query()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);
        $this->otherEnrollment = Enrollment::query()->create([
            'user_id' => $this->otherUser->id,
            'course_id' => $this->course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);

        $bank = QuestionBank::query()->create([
            'lesson_id' => $this->lesson->id,
            'status' => QuestionBankStatus::Active,
            'title' => ['en' => 'Bank', 'ar' => 'بنك'],
            'description' => ['en' => 'Desc', 'ar' => 'وصف'],
        ]);

        $this->quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $this->lesson->id,
            'passing_score' => 60,
            'max_attempts' => 3,
            'time_limit_seconds' => 600,
            'is_required' => false,
            'selection_mode' => QuizSelectionMode::Fixed,
            'title' => ['en' => 'Official Quiz', 'ar' => 'اختبار'],
        ]);
        $this->quiz->questionBanks()->sync([$bank->id]);

        $this->question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'quiz_id' => $this->quiz->id,
            'question_type' => QuestionType::SingleChoice,
            'difficulty' => QuestionDifficulty::Easy,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'sort_order' => 1,
            'body' => ['en' => 'Pick correct', 'ar' => 'اختر'],
            'explanation' => ['en' => 'Explanation', 'ar' => 'شرح'],
            'answer_key' => ['hidden' => true],
        ]);

        $this->correctOption = QuestionOption::query()->create([
            'question_id' => $this->question->id,
            'is_correct' => true,
            'sort_order' => 1,
            'label' => ['en' => 'Correct', 'ar' => 'صح'],
        ]);
        $this->wrongOption = QuestionOption::query()->create([
            'question_id' => $this->question->id,
            'is_correct' => false,
            'sort_order' => 2,
            'label' => ['en' => 'Wrong', 'ar' => 'خطأ'],
        ]);
    }
}
