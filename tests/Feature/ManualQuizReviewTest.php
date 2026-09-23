<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Application\Services\ManualQuizReviewService;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManualQuizReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_grade_pending_long_answer_and_finalize_attempt(): void
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'review-course',
            'title' => ['en' => 'Review', 'ar' => 'مراجعة'],
            'is_published' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'review-lesson',
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $enrollment = Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lesson->id,
            'title' => ['en' => 'Quiz', 'ar' => 'اختبار'],
            'passing_score' => 50,
            'is_required' => true,
        ]);
        $question = Question::query()->create([
            'quiz_id' => $quiz->id,
            'question_type' => QuestionType::LongAnswer,
            'points' => 10,
            'sort_order' => 1,
            'body' => ['en' => 'Explain gravity', 'ar' => 'اشرح الجاذبية'],
        ]);

        $service = app(QuizAttemptService::class);
        $attempt = $service->start($user, $quiz, $enrollment);
        $graded = $service->submit($attempt, [
            ['question_id' => $question->id, 'text_answer' => 'Gravity pulls things down.'],
        ]);

        $this->assertSame(AttemptStatus::PendingReview, $graded->status);
        $answer = $graded->answers()->firstOrFail();
        $this->assertTrue((bool) $answer->needs_manual_review);

        $final = app(ManualQuizReviewService::class)->gradeAnswer($answer, 10, true);

        $this->assertSame(AttemptStatus::Graded, $final->status);
        $this->assertTrue((bool) $final->passed);
        $this->assertSame(100.0, (float) $final->percentage);
        $this->assertFalse((bool) $final->answers()->firstOrFail()->needs_manual_review);
    }
}
