<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class InteractiveAsLearningContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_interactive_is_a_topic_not_a_quiz_question(): void
    {
        [$user, $lesson, $activity, $quiz, $question] = $this->seedLessonWithInteractiveAndQuiz();
        Sanctum::actingAs($user);

        $course = $lesson->course()->first();
        $curriculum = $this->getJson("/api/v1/courses/{$course->slug}/curriculum")->assertOk();
        $topic = collect($curriculum->json('data.lessons.0.topics'))->firstWhere('content_type', 'interactive');
        $this->assertNotNull($topic);
        $this->assertSame($activity->id, $topic['interactive']['activity_id']);
        $this->assertArrayHasKey('can_start', $topic['interactive']);
        $this->assertTrue($topic['interactive']['can_start']);

        $this->getJson("/api/v1/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.interactive_activities', []);
        $start = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts");
        $start->assertCreated();
        $this->assertNull($start->json('data.quiz_attempt_id'));

        $attemptId = $start->json('data.attempt_id') ?? $start->json('data.id');
        $this->postJson("/api/v1/interactive-activity-attempts/{$attemptId}/progress", [
            'completed_challenges' => 3,
            'total_challenges' => 2,
        ])->assertUnprocessable();
        $this->postJson("/api/v1/interactive-activity-attempts/{$attemptId}/progress", [
            'completed_challenges' => 1,
            'total_challenges' => 2,
        ])->assertOk();
        $this->postJson("/api/v1/interactive-activity-attempts/{$attemptId}/result", [
            'completed' => true,
            'score' => 40,
            'max_score' => 50,
        ])->assertOk();

        $quizStart = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated();
        $quizAttemptId = $quizStart->json('data.attempt_id') ?? $quizStart->json('data.id');
        $this->postJson("/api/v1/quiz-attempts/{$quizAttemptId}/answers", [
            'question_id' => $question->id,
            'answer' => ['option_id' => $question->options()->where('is_correct', true)->value('id')],
        ])->assertOk();
        $submit = $this->postJson("/api/v1/quiz-attempts/{$quizAttemptId}/submit")->assertOk();
        $this->assertEquals(100, (float) $submit->json('data.percentage'));
    }

    public function test_idor_and_unpublished_activity_are_rejected(): void
    {
        [$user, $lesson, $activity] = $this->seedLessonWithInteractiveAndQuiz();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        $attemptId = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts")
            ->assertCreated()
            ->json('data.attempt_id');

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/interactive-activity-attempts/{$attemptId}")->assertForbidden();

        $activity->update(['status' => InteractiveActivityStatus::Draft]);
        Sanctum::actingAs($user);
        $this->getJson("/api/v1/interactive-activities/{$activity->id}/launch")->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Lesson, 2: InteractiveActivity, 3: Quiz, 4: Question}
     */
    private function seedLessonWithInteractiveAndQuiz(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'interactive-arch-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Arch', 'ar' => 'كورس'],
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
            'slug' => 'interactive-lab',
            'sort_order' => 2,
            'content_type' => 'interactive',
            'is_published' => true,
            'title' => ['en' => 'Light lab', 'ar' => 'مختبر الضوء'],
        ]);
        $activity = InteractiveActivity::query()->create([
            'lesson_id' => $lesson->id,
            'topic_id' => $topic->id,
            'status' => InteractiveActivityStatus::Published,
            'activity_type' => InteractiveActivityType::VirtualLab,
            'title' => ['en' => 'Light lab', 'ar' => 'مختبر الضوء'],
            'activity_package_path' => 'interactive-activities/demo/v1/index.html',
            'entry_file' => 'index.html',
            'version' => 1,
        ]);
        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'passing_score' => 50,
            'selection_mode' => QuizSelectionMode::Fixed,
            'title' => ['en' => 'Quiz', 'ar' => 'اختبار'],
        ]);
        $question = Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::SingleChoice,
            'status' => QuestionStatus::Published,
            'points' => 1,
            'sort_order' => 1,
            'body' => ['en' => '2+2?', 'ar' => '2+2؟'],
        ]);
        QuestionOption::query()->create([
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

        return [$user, $lesson, $activity, $quiz, $question];
    }
}
