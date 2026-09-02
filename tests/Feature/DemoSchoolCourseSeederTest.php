<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Database\Seeders\DemoSchoolCourseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DemoSchoolCourseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(DemoSchoolCourseSeeder::class);
    }

    public function test_demo_school_course_exists_and_is_published_school_type(): void
    {
        $course = Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->first();

        $this->assertNotNull($course);
        $this->assertSame(AccessType::School, $course->access_type);
        $this->assertTrue($course->is_published);
    }

    public function test_demo_course_has_lessons_topics_and_quizzes(): void
    {
        $course = Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->firstOrFail();

        $this->assertGreaterThanOrEqual(5, $course->lessons()->count());
        $this->assertGreaterThanOrEqual(10, $course->lessons()->withCount('topics')->get()->sum('topics_count'));
        $this->assertGreaterThanOrEqual(4, Quiz::query()->whereIn(
            'quizable_id',
            $course->lessons()->pluck('id'),
        )->count());
    }

    public function test_demo_course_has_three_active_plans_with_different_prices(): void
    {
        $course = Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->firstOrFail();

        $activePlans = CoursePlan::query()
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(3, $activePlans);
        $this->assertSame('Starter Plan', $activePlans[0]->getTranslation('name', 'en'));
        $this->assertSame('499.00', number_format((float) $activePlans[1]->price, 2, '.', ''));
        $this->assertSame(60, $activePlans[2]->duration_days);
        $this->assertTrue(
            CoursePlan::query()
                ->where('course_id', $course->id)
                ->where('is_active', false)
                ->exists()
        );
    }

    public function test_plans_have_different_entitlement_configurations(): void
    {
        $course = Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->firstOrFail();

        $starter = CoursePlan::query()
            ->where('course_id', $course->id)
            ->where('name->en', 'Starter Plan')
            ->firstOrFail();
        $complete = CoursePlan::query()
            ->where('course_id', $course->id)
            ->where('name->en', 'Complete Plan')
            ->firstOrFail();
        $exam = CoursePlan::query()
            ->where('course_id', $course->id)
            ->where('name->en', 'Exam Preparation Plan')
            ->firstOrFail();

        $this->assertGreaterThan($starter->entitlements()->count(), $complete->entitlements()->count());
        $this->assertGreaterThan(0, $exam->entitlements()->count());
        $this->assertTrue($complete->grant_certificate);
        $this->assertFalse($starter->grant_certificate);
    }

    public function test_demo_enrollment_uses_complete_plan_and_is_active(): void
    {
        $course = Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->firstOrFail();
        $user = User::query()->where('email', DemoSchoolCourseSeeder::DEMO_STUDENT_EMAIL)->firstOrFail();
        $completePlan = CoursePlan::query()
            ->where('course_id', $course->id)
            ->where('name->en', 'Complete Plan')
            ->firstOrFail();

        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertSame($completePlan->id, $enrollment->course_plan_id);
        $this->assertNull($enrollment->expires_at);
        $this->assertTrue($enrollment->grant_certificate);
    }

    public function test_official_quiz_score_demo_data_via_api(): void
    {
        $course = Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->firstOrFail();
        $user = User::query()->where('email', DemoSchoolCourseSeeder::DEMO_STUDENT_EMAIL)->firstOrFail();
        $quiz = Quiz::query()
            ->where('quizable_id', $course->lessons()->where('slug', 'intro-physics')->value('id'))
            ->firstOrFail();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.has_previous_attempt', true)
            ->assertJsonPath('data.official_attempt_number', 1);

        $officialScore = (float) $this->getJson("/api/v1/quizzes/{$quiz->id}")->json('data.official_score');
        $this->assertEqualsWithDelta(60.0, $officialScore, 0.1);
    }

    public function test_course_plans_api_returns_demo_plans(): void
    {
        $this->getJson('/api/v1/courses/'.DemoSchoolCourseSeeder::COURSE_SLUG.'/plans')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Starter Plan')
            ->assertJsonPath('data.1.price', '499.00')
            ->assertJsonPath('data.2.max_quiz_attempts', 1);
    }

    public function test_running_seeder_twice_does_not_duplicate_demo_course_or_plans(): void
    {
        $courseCountBefore = Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->count();
        $planCountBefore = CoursePlan::query()
            ->where('course_id', Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->value('id'))
            ->where('is_active', true)
            ->count();

        $this->seed(DemoSchoolCourseSeeder::class);

        $this->assertSame($courseCountBefore, Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->count());
        $this->assertSame($planCountBefore, CoursePlan::query()
            ->where('course_id', Course::query()->where('slug', DemoSchoolCourseSeeder::COURSE_SLUG)->value('id'))
            ->where('is_active', true)
            ->count());
    }
}
