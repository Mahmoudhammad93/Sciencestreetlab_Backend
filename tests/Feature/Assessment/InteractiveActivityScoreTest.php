<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivityAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizOfficialScore;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class InteractiveActivityScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_enrolled_user_can_submit_a_calculated_score(): void
    {
        [$user, , $activity] = $this->seedActivity();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 8,
            'maxScore' => 10,
            'durationSeconds' => 145,
            'result' => 'completed',
            'percentage' => 100,
            'metadata' => ['source' => 'game'],
        ])->assertCreated();

        $response
            ->assertJsonPath('data.score', 8)
            ->assertJsonPath('data.max_score', 10)
            ->assertJsonPath('data.percentage', 80)
            ->assertJsonPath('data.duration_seconds', 145)
            ->assertJsonPath('data.status', 'completed');

        $attempt = InteractiveActivityAttempt::query()->firstOrFail();
        $this->assertSame(80.0, (float) $attempt->percentage);
        $this->assertSame(100, $attempt->metadata['ignored_client_percentage']);
        $this->assertFalse($attempt->score_verified);
        $this->assertNull($attempt->verified_score);
        $this->assertSame(0, QuizOfficialScore::query()->count());
    }

    public function test_unauthenticated_and_unauthorized_users_cannot_submit(): void
    {
        [, , $activity] = $this->seedActivity();

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 1,
            'max_score' => 10,
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 1,
            'max_score' => 10,
        ])->assertForbidden();
    }

    public function test_score_values_are_validated_by_the_backend(): void
    {
        [$user, , $activity] = $this->seedActivity();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => -1,
            'max_score' => 10,
        ])->assertStatus(422);

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 1,
            'max_score' => 0,
        ])->assertStatus(422);

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 11,
            'max_score' => 10,
        ])->assertStatus(422);

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 1,
            'max_score' => 10,
            'duration_seconds' => -5,
        ])->assertStatus(422);

        $this->assertSame(0, InteractiveActivityAttempt::query()->count());
    }

    public function test_best_score_and_attempt_history_allow_duplicate_attempts(): void
    {
        [$user, , $activity] = $this->seedActivity();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 5,
            'max_score' => 10,
            'duration_seconds' => 40,
        ])->assertCreated();

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [
            'score' => 8,
            'max_score' => 10,
            'duration_seconds' => 30,
        ])->assertCreated();

        $this->getJson("/api/v1/interactive-activities/{$activity->id}/my-score")
            ->assertOk()
            ->assertJsonPath('data.activity_id', $activity->id)
            ->assertJsonPath('data.best_score', 8)
            ->assertJsonPath('data.max_score', 10)
            ->assertJsonPath('data.percentage', 80)
            ->assertJsonPath('data.attempts_count', 2)
            ->assertJsonPath('data.latest_attempt.score', 8)
            ->assertJsonPath('data.latest_attempt.percentage', 80);

        $this->getJson("/api/v1/interactive-activities/{$activity->id}/attempts")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.score', 8)
            ->assertJsonPath('data.1.score', 5);

        $this->assertSame(0, QuizOfficialScore::query()->count());
    }

    public function test_empty_post_still_starts_an_attempt_and_quiz_scores_are_untouched(): void
    {
        [$user, , $activity] = $this->seedActivity();
        Storage::disk('public')->put(
            "interactive-activities/{$activity->uuid}/v1/index.html",
            '<html><body>ok</body></html>'
        );
        $activity->update([
            'activity_package_path' => "interactive-activities/{$activity->uuid}/v1/index.html",
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/interactive-activities/{$activity->id}/attempts", [])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonStructure(['data' => ['attempt_id', 'launch']]);

        $this->assertSame(0, QuizOfficialScore::query()->count());
    }

    /**
     * @return array{0: User, 1: Lesson, 2: InteractiveActivity}
     */
    private function seedActivity(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'score-course-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'C', 'ar' => 'ك'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'score-lesson',
            'lesson_type' => LessonType::Theory,
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'L', 'ar' => 'د'],
        ]);
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);
        $activity = InteractiveActivity::query()->create([
            'lesson_id' => $lesson->id,
            'status' => InteractiveActivityStatus::Published,
            'activity_type' => InteractiveActivityType::Custom->value,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 10,
            'version' => 1,
            'entry_file' => 'index.html',
            'title' => ['en' => 'Game', 'ar' => 'لعبة'],
        ]);

        return [$user, $lesson, $activity];
    }
}
