<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Application\Services\CoursePlanAccessService;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CoursePlanSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_plan_with_entitlements_and_fixed_duration(): void
    {
        $this->seed();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lessons = $course->lessons()->orderBy('sort_order')->get();
        $lesson1 = $lessons->first();
        $lesson2 = $lessons->skip(1)->first();

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'خطة الامتحان', 'en' => 'Exam Prep'],
            'description' => ['ar' => 'وصف', 'en' => 'Description'],
            'price' => 30,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => false,
            'duration_days' => 30,
            'max_quiz_attempts' => 3,
            'grant_certificate' => false,
        ]);

        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$lesson1->id],
            'topic_ids' => [],
            'quiz_ids' => [],
            'interactive_activity_ids' => [],
        ]);

        $this->assertDatabaseHas('course_plans', [
            'id' => $plan->id,
            'price' => '30.00',
            'duration_days' => 30,
        ]);
        $this->assertDatabaseHas('course_plan_entitlements', [
            'course_plan_id' => $plan->id,
            'entitleable_type' => Lesson::class,
            'entitleable_id' => $lesson1->id,
        ]);

        if ($lesson2 !== null) {
            $this->assertDatabaseMissing('course_plan_entitlements', [
                'course_plan_id' => $plan->id,
                'entitleable_id' => $lesson2->id,
            ]);
        }
    }

    public function test_free_plan_creates_enrollment_with_snapshot_and_expiration(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $lesson->id)->first();

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'مجاني', 'en' => 'Free Plan'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => false,
            'duration_days' => 30,
            'max_quiz_attempts' => 3,
            'grant_certificate' => false,
        ]);

        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => [],
            'quiz_ids' => $quiz ? [$quiz->id] : [],
            'interactive_activity_ids' => [],
        ]);

        $enrollment = app(EnrollUserService::class)->enrollWithPlan($user, $plan);

        $this->assertSame($plan->id, $enrollment->course_plan_id);
        $this->assertNotNull($enrollment->expires_at);
        $this->assertDatabaseHas('enrollment_entitlements', [
            'enrollment_id' => $enrollment->id,
            'entitleable_type' => Lesson::class,
            'entitleable_id' => $lesson->id,
        ]);
    }

    public function test_plan_access_blocks_excluded_lesson(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $included = $course->lessons()->orderBy('sort_order')->firstOrFail();
        $excluded = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'plan-excluded-lesson',
            'lesson_type' => 'theory',
            'sort_order' => 99,
            'is_published' => true,
            'title' => ['ar' => 'مستبعد', 'en' => 'Excluded'],
        ]);

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'جزئي', 'en' => 'Partial'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'max_quiz_attempts' => 3,
        ]);

        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$included->id],
            'topic_ids' => [],
            'quiz_ids' => [],
            'interactive_activity_ids' => [],
        ]);

        $enrollment = app(EnrollUserService::class)->enroll($user, $course, null, $plan);
        $access = app(CoursePlanAccessService::class);

        $this->assertTrue($access->canAccessLesson($enrollment, $included));
        $this->assertFalse($access->canAccessLesson($enrollment, $excluded));
    }

    public function test_expired_enrollment_loses_access(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();

        $enrollment = Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now()->subDays(40),
            'started_at' => now()->subDays(40),
            'expires_at' => now()->subDay(),
            'progress_percent' => 0,
        ]);

        $access = app(CoursePlanAccessService::class);
        $this->assertFalse($access->isEnrollmentActive($enrollment));
        $this->assertFalse($access->canAccessLesson($enrollment, $lesson));
    }

    public function test_first_submitted_attempt_becomes_official_and_retries_do_not_change_it(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $lesson->id)->firstOrFail();
        $question = $quiz->questions()->firstOrFail();
        $correct = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', true)->firstOrFail();
        $wrong = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', false)->firstOrFail();

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'اختبار', 'en' => 'Quiz Plan'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'max_quiz_attempts' => 3,
        ]);
        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => [],
            'quiz_ids' => [$quiz->id],
            'interactive_activity_ids' => [],
        ]);

        $enrollment = app(EnrollUserService::class)->enroll($user, $course, null, $plan);
        $service = app(QuizAttemptService::class);

        // Start attempt 1 but abandon (no submit)
        $attempt1 = $service->start($user, $quiz, $enrollment);
        $attempt1->update(['status' => AttemptStatus::Abandoned]);

        // Attempt 2: first submitted attempt — wrong answer (~0%)
        $attempt2 = $service->start($user, $quiz, $enrollment);
        $service->submit($attempt2, [
            ['question_id' => $question->id, 'selected_option_ids' => [$wrong->id]],
        ]);

        $attempt2->refresh();
        $this->assertTrue($attempt2->is_official);
        $officialScore = (float) $attempt2->percentage;

        // Attempt 3: higher score must not become official
        $attempt3 = $service->start($user, $quiz, $enrollment);
        $service->submit($attempt3, [
            ['question_id' => $question->id, 'selected_option_ids' => [$correct->id]],
        ]);

        $attempt3->refresh();
        $this->assertFalse($attempt3->is_official);
        $this->assertGreaterThan($officialScore, (float) $attempt3->percentage);

        Sanctum::actingAs($user);
        $result = $this->getJson("/api/v1/quiz-attempts/{$attempt3->id}/result")->assertOk();
        $result->assertJsonPath('data.is_official', false);
        $this->assertEquals($officialScore, (float) $result->json('data.official_score'));
        $result->assertJsonPath('data.official_attempt_number', 2);
    }

    public function test_max_quiz_attempts_from_plan_are_enforced(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $lesson->id)->firstOrFail();
        $quiz->update(['max_attempts' => null]);

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'حد', 'en' => 'Limited'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'max_quiz_attempts' => 2,
        ]);
        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => [],
            'quiz_ids' => [$quiz->id],
            'interactive_activity_ids' => [],
        ]);

        $enrollment = app(EnrollUserService::class)->enroll($user, $course, null, $plan);
        $service = app(QuizAttemptService::class);
        $question = $quiz->questions()->firstOrFail();
        $wrong = QuestionOption::query()->where('question_id', $question->id)->where('is_correct', false)->firstOrFail();

        $attempt1 = $service->start($user, $quiz, $enrollment);
        $service->submit($attempt1, [
            ['question_id' => $question->id, 'selected_option_ids' => [$wrong->id]],
        ]);

        $attempt2 = $service->start($user, $quiz, $enrollment);
        $service->submit($attempt2, [
            ['question_id' => $question->id, 'selected_option_ids' => [$wrong->id]],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('MAX_ATTEMPTS_REACHED');
        $service->start($user, $quiz, $enrollment);
    }

    public function test_payment_with_plan_creates_enrollment_idempotently(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'مدفوع', 'en' => 'Paid Plan'],
            'price' => 100,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'max_quiz_attempts' => 3,
        ]);
        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => [],
            'quiz_ids' => [],
            'interactive_activity_ids' => [],
        ]);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $product->update(['course_id' => $course->id, 'course_plan_id' => $plan->id]);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();
        $orderId = $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'Student',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'shipping_address' => ['city' => 'Cairo', 'country' => 'EG'],
        ])->json('data.id');

        $paymentId = $this->postJson("/api/v1/checkout/{$orderId}/pay")->json('data.payment_id');
        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete")->assertOk();
        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete")->assertOk();

        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        $enrollment = Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail();
        $this->assertSame($plan->id, $enrollment->course_plan_id);
        $this->assertDatabaseHas('enrollment_entitlements', [
            'enrollment_id' => $enrollment->id,
            'entitleable_id' => $lesson->id,
        ]);
    }

    public function test_course_access_api_returns_plan_summary(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'API', 'en' => 'API Plan'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'max_quiz_attempts' => 3,
            'grant_certificate' => true,
        ]);
        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$lesson->id],
            'topic_ids' => [],
            'quiz_ids' => [],
            'interactive_activity_ids' => [],
        ]);

        app(EnrollUserService::class)->enroll($user, $course, null, $plan);

        $this->getJson('/api/v1/courses/microscope-course/access')
            ->assertOk()
            ->assertJsonPath('enrolled', true)
            ->assertJsonPath('plan.name', 'API Plan')
            ->assertJsonPath('access.certificate', true);
    }
}
