<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FrontendApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_question_bank_and_quiz_frontend_contract(): void
    {
        [$user, $lesson, $bank, $quiz, $question, $option] = $this->seedContractFixture();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/lessons/{$lesson->id}/question-banks")
            ->assertOk()
            ->assertJsonPath('data.0.id', $bank->id)
            ->assertJsonMissingPath('data.0.created_by')
            ->assertJsonStructure([
                'data' => [['id', 'uuid', 'title', 'description', 'question_count', 'lesson', 'available_quizzes']],
            ]);

        $this->getJson("/api/v1/question-banks/{$bank->id}")
            ->assertOk()
            ->assertJsonPath('data.question_count', 1)
            ->assertJsonPath('data.available_quizzes.0.id', $quiz->id);

        $this->getJson("/api/v1/question-banks/{$bank->id}/questions?difficulty=easy&type=single_choice")
            ->assertOk()
            ->assertJsonPath('data.0.id', $question->id)
            ->assertJsonMissingPath('data.0.answer_key')
            ->assertJsonMissingPath('data.0.options.0.is_correct');

        $this->getJson("/api/v1/questions/{$question->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'single_choice')
            ->assertJsonMissingPath('data.answer_key');

        $start = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'attempt_id',
                    'id',
                    'status',
                    'started_at',
                    'expires_at',
                    'total_questions',
                    'questions',
                    'progress',
                ],
            ]);

        $attemptId = $start->json('data.attempt_id');
        $this->assertSame($attemptId, $start->json('data.id'));
        $this->assertSame(1, $start->json('data.total_questions'));

        $this->getJson("/api/v1/quiz-attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('data.attempt_id', $attemptId)
            ->assertJsonStructure(['data' => ['remaining_seconds', 'progress', 'questions', 'answers']]);

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $question->id,
            'answer' => ['option_id' => $option->id],
        ])->assertOk()->assertJsonPath('data.saved', true);

        $this->getJson("/api/v1/quiz-attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('data.answered_count', 1)
            ->assertJsonPath('data.answers.0.answer.option_id', $option->id);

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/submit")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'status', 'score', 'percentage', 'passed',
                    'attempt_number', 'time_taken', 'time_taken_seconds',
                    'total_points', 'earned_points', 'submitted_at',
                    'question_results' => [
                        ['question_id', 'is_correct', 'user_answer', 'correct_answer'],
                    ],
                ],
            ])
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonPath('data.question_results.0.user_answer.option_id', $option->id)
            ->assertJsonPath('data.question_results.0.correct_answer.option_id', $option->id);

        $this->getJson("/api/v1/quiz-attempts/{$attemptId}/result")
            ->assertOk()
            ->assertJsonPath('data.attempt_id', $attemptId)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonPath('data.question_results.0.user_answer.option_id', $option->id)
            ->assertJsonPath('data.question_results.0.correct_answer.option_id', $option->id);
    }

    public function test_interactive_signed_url_and_result_endpoint(): void
    {
        [$user, $lesson, $bank, $quiz] = $this->seedContractFixture();

        Storage::fake('public');
        Storage::disk('public')->put(
            'interactive-activities/demo/v1/index.html',
            '<html><body>ok</body></html>'
        );

        Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => 'intro-video',
            'sort_order' => 1,
            'content_type' => 'video',
            'is_published' => true,
            'title' => ['en' => 'Intro', 'ar' => 'مقدمة'],
        ]);
        $topic = Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => 'interactive-topic',
            'sort_order' => 2,
            'content_type' => 'interactive',
            'is_published' => true,
            'title' => ['en' => 'Lab', 'ar' => 'مختبر'],
        ]);
        $activity = InteractiveActivity::query()->create([
            'lesson_id' => $lesson->id,
            'topic_id' => $topic->id,
            'status' => InteractiveActivityStatus::Published,
            'activity_type' => InteractiveActivityType::VirtualLab,
            'title' => ['en' => 'Lab', 'ar' => 'مختبر'],
            'activity_package_path' => 'interactive-activities/demo/v1/index.html',
            'entry_file' => 'index.html',
            'version' => 1,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/interactive-activities/{$activity->id}/launch")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at', 'sandbox']]);

        $start = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts")
            ->assertCreated();
        $attemptId = $start->json('data.attempt_id') ?? $start->json('data.id');
        $this->assertNull($start->json('data.quiz_attempt_id'));

        $this->postJson("/api/v1/interactive-activity-attempts/{$attemptId}/result", [
            'completed' => true,
            'score' => 999,
            'max_score' => 10,
        ])->assertOk()->assertJsonPath('data.score_verified', false);

        $quizStart = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated();
        $this->assertSame([], $quizStart->json('data.interactive_activities') ?? []);
    }

    public function test_access_denied_without_enrollment(): void
    {
        [$user, $lesson, $bank] = $this->seedContractFixture();
        Enrollment::query()->where('user_id', $user->id)->delete();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/question-banks/{$bank->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'QUESTION_ACCESS_DENIED');
    }

    /**
     * @return array{0: User, 1: Lesson, 2: QuestionBank, 3: Quiz, 4?: Question, 5?: QuestionOption}
     */
    private function seedContractFixture(bool $withSingleChoice = true): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'contract-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Contract Course', 'ar' => 'كورس'],
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
            'status' => QuestionBankStatus::Active,
            'title' => ['en' => 'Bank', 'ar' => 'بنك'],
            'description' => ['en' => 'Desc', 'ar' => 'وصف'],
        ]);
        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'passing_score' => 50,
            'time_limit_seconds' => 600,
            'is_required' => false,
            'selection_mode' => QuizSelectionMode::Fixed,
            'title' => ['en' => 'Quiz', 'ar' => 'اختبار'],
        ]);
        $quiz->questionBanks()->sync([$bank->id]);

        if (! $withSingleChoice) {
            return [$user, $lesson, $bank, $quiz];
        }

        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::SingleChoice,
            'difficulty' => QuestionDifficulty::Easy,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'sort_order' => 1,
            'body' => ['en' => '2+2?', 'ar' => '2+2؟'],
            'explanation' => ['en' => 'Four', 'ar' => 'أربعة'],
            'answer_key' => ['hidden' => true],
        ]);
        $correct = QuestionOption::query()->create([
            'question_id' => $question->id,
            'is_correct' => true,
            'sort_order' => 1,
            'label' => ['en' => '4', 'ar' => '4'],
        ]);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'is_correct' => false,
            'sort_order' => 2,
            'label' => ['en' => '5', 'ar' => '5'],
        ]);

        return [$user, $lesson, $bank, $quiz, $question, $correct];
    }
}
