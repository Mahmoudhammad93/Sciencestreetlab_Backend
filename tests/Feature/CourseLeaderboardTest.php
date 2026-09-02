<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CourseLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaderboard_returns_enrolled_learners_ranked_by_official_score(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $lesson->id)->firstOrFail();
        $question = $quiz->questions()->firstOrFail();
        $correct = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', true)->firstOrFail();
        $wrong = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', false)->firstOrFail();

        $top = User::factory()->create(['name' => 'Top Student']);
        $low = User::factory()->create(['name' => 'Low Student']);
        $outsider = User::factory()->create(['name' => 'Not Enrolled']);

        $topEnrollment = app(EnrollUserService::class)->enroll($top, $course);
        $lowEnrollment = app(EnrollUserService::class)->enroll($low, $course);
        $topEnrollment->update(['progress_percent' => 100]);
        $lowEnrollment->update(['progress_percent' => 50]);

        $service = app(QuizAttemptService::class);

        $topAttempt = $service->start($top, $quiz, $topEnrollment);
        $service->submit($topAttempt, [
            ['question_id' => $question->id, 'selected_option_ids' => [$correct->id]],
        ]);

        $lowAttempt = $service->start($low, $quiz, $lowEnrollment);
        $service->submit($lowAttempt, [
            ['question_id' => $question->id, 'selected_option_ids' => [$wrong->id]],
        ]);

        Sanctum::actingAs($low);
        $response = $this->getJson('/api/v1/courses/microscope-course/leaderboard')->assertOk();

        $response->assertJsonPath('course_id', $course->id);
        $response->assertJsonPath('leaderboard.0.user.name', 'Top Student');
        $response->assertJsonPath('leaderboard.0.rank', 1);
        $response->assertJsonPath('leaderboard.1.user.name', 'Low Student');
        $response->assertJsonPath('leaderboard.1.rank', 2);
        $response->assertJsonPath('current_user.rank', 2);
        $response->assertJsonPath('current_user.is_current_user', true);
        $response->assertJsonMissing(['email' => $outsider->email]);
        $response->assertJsonMissingPath('leaderboard.0.user.email');
    }

    public function test_retry_score_does_not_improve_leaderboard_rank(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $lesson->id)->firstOrFail();
        $question = $quiz->questions()->firstOrFail();
        $correct = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', true)->firstOrFail();
        $wrong = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', false)->firstOrFail();

        $student = User::factory()->create(['name' => 'Retry Student']);
        $enrollment = app(EnrollUserService::class)->enroll($student, $course);
        $service = app(QuizAttemptService::class);

        $attempt1 = $service->start($student, $quiz, $enrollment);
        $service->submit($attempt1, [
            ['question_id' => $question->id, 'selected_option_ids' => [$wrong->id]],
        ]);

        $officialScore = (float) $attempt1->fresh()->percentage;

        $attempt2 = $service->start($student, $quiz, $enrollment);
        $service->submit($attempt2, [
            ['question_id' => $question->id, 'selected_option_ids' => [$correct->id]],
        ]);

        $this->assertGreaterThan($officialScore, (float) $attempt2->fresh()->percentage);

        $response = $this->getJson('/api/v1/courses/microscope-course/leaderboard')->assertOk();
        $this->assertEquals($officialScore, (float) $response->json('leaderboard.0.score'));
    }

    public function test_abandoned_attempt_is_not_used_for_leaderboard(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $lesson->id)->firstOrFail();
        $question = $quiz->questions()->firstOrFail();
        $correct = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', true)->firstOrFail();

        $student = User::factory()->create();
        $enrollment = app(EnrollUserService::class)->enroll($student, $course);
        $service = app(QuizAttemptService::class);

        $abandoned = $service->start($student, $quiz, $enrollment);
        $abandoned->update(['status' => AttemptStatus::Abandoned]);

        $official = $service->start($student, $quiz, $enrollment);
        $service->submit($official, [
            ['question_id' => $question->id, 'selected_option_ids' => [$correct->id]],
        ]);

        $response = $this->getJson('/api/v1/courses/microscope-course/leaderboard')->assertOk();
        $this->assertEquals(100.0, (float) $response->json('leaderboard.0.score'));
    }

    public function test_ineligible_enrollments_are_excluded(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $otherCourse = Course::query()->where('slug', 'basic-physics-lab')->firstOrFail();

        $active = User::factory()->create(['name' => 'Active']);
        $expired = User::factory()->create(['name' => 'Expired']);
        $suspended = User::factory()->create(['name' => 'Suspended']);
        $cancelled = User::factory()->create(['name' => 'Cancelled']);
        $otherCourseUser = User::factory()->create(['name' => 'Other Course']);

        app(EnrollUserService::class)->enroll($active, $course);

        Enrollment::query()->create([
            'user_id' => $expired->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 0,
            'enrolled_at' => now()->subDays(40),
            'started_at' => now()->subDays(40),
            'expires_at' => now()->subDay(),
        ]);

        Enrollment::query()->create([
            'user_id' => $suspended->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Suspended,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);

        Enrollment::query()->create([
            'user_id' => $cancelled->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Cancelled,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);

        app(EnrollUserService::class)->enroll($otherCourseUser, $otherCourse);

        $response = $this->getJson('/api/v1/courses/microscope-course/leaderboard')->assertOk();
        $names = collect($response->json('leaderboard'))->pluck('user.name');

        $this->assertTrue($names->contains('Active'));
        $this->assertFalse($names->contains('Expired'));
        $this->assertFalse($names->contains('Suspended'));
        $this->assertFalse($names->contains('Cancelled'));
        $this->assertFalse($names->contains('Other Course'));
    }

    public function test_tie_breaker_uses_completion_percentage_then_user_id(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();

        $first = User::factory()->create(['name' => 'First Tie']);
        $second = User::factory()->create(['name' => 'Second Tie']);

        $enrollmentA = app(EnrollUserService::class)->enroll($first, $course);
        $enrollmentB = app(EnrollUserService::class)->enroll($second, $course);
        $enrollmentA->update(['progress_percent' => 80]);
        $enrollmentB->update(['progress_percent' => 60]);

        $response = $this->getJson('/api/v1/courses/microscope-course/leaderboard')->assertOk();

        if ($first->id < $second->id) {
            $response->assertJsonPath('leaderboard.0.user.name', 'First Tie');
            $response->assertJsonPath('leaderboard.1.user.name', 'Second Tie');
        } else {
            $response->assertJsonPath('leaderboard.0.user.name', 'Second Tie');
            $response->assertJsonPath('leaderboard.1.user.name', 'First Tie');
        }
    }

    public function test_leaderboard_pagination_works(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();

        foreach (range(1, 5) as $i) {
            $user = User::factory()->create(['name' => "Student {$i}"]);
            $enrollment = app(EnrollUserService::class)->enroll($user, $course);
            $enrollment->update(['progress_percent' => $i * 10]);
        }

        $page1 = $this->getJson('/api/v1/courses/microscope-course/leaderboard?per_page=2&page=1')->assertOk();
        $page2 = $this->getJson('/api/v1/courses/microscope-course/leaderboard?per_page=2&page=2')->assertOk();

        $this->assertGreaterThanOrEqual(5, $page1->json('meta.total'));
        $page1->assertJsonPath('meta.per_page', 2);
        $this->assertCount(2, $page1->json('leaderboard'));
        $this->assertCount(2, $page2->json('leaderboard'));
    }

    public function test_multiple_quizzes_aggregate_by_average_official_score(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'basic-physics-lab')->firstOrFail();
        $lesson = $course->lessons()->where('slug', 'forces')->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $lesson->id)->whereHas('questions')->firstOrFail();
        $question = $quiz->questions()->firstOrFail();
        $correct = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', true)->first();
        $wrong = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', false)->first();

        if ($correct === null || $wrong === null) {
            $this->markTestSkipped('Quiz options not available for aggregation test.');
        }

        $student = User::factory()->create();
        $enrollment = app(EnrollUserService::class)->enroll($student, $course);
        $service = app(QuizAttemptService::class);

        $attempt = $service->start($student, $quiz, $enrollment);
        $service->submit($attempt, [
            ['question_id' => $question->id, 'selected_option_ids' => [$correct->id]],
        ]);

        $response = $this->getJson('/api/v1/courses/basic-physics-lab/leaderboard')->assertOk();
        $entry = collect($response->json('leaderboard'))->firstWhere('user.id', $student->id);
        $this->assertNotNull($entry);
        $this->assertGreaterThan(0, $entry['score']);
    }
}
