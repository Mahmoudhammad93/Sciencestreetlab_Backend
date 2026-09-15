<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CoursePlanAccessService;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\EnrollmentEntitlement;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoursePlanAccessOverrideTest extends TestCase
{
    use RefreshDatabase;

    private CourseAccessService $access;

    private CoursePlanEntitlementSyncService $sync;

    private EnrollUserService $enroll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->access = app(CourseAccessService::class);
        $this->sync = app(CoursePlanEntitlementSyncService::class);
        $this->enroll = app(EnrollUserService::class);
    }

    public function test_plan_with_lesson_one_only_grants_lesson_and_all_its_topics(): void
    {
        [$course, $lessons, $topicsByLesson] = $this->courseWithTwoLessons();
        $lesson1 = $lessons[0];
        $lesson2 = $lessons[1];

        $enrollment = $this->enrollWithPlan($course, [
            'lesson_ids' => [$lesson1->id],
        ]);

        $this->assertTrue($this->access->canAccessLesson($enrollment, $lesson1));
        $this->assertFalse($this->access->canAccessLesson($enrollment, $lesson2));

        foreach ($topicsByLesson[$lesson1->id] as $topic) {
            $this->assertTrue($this->access->canAccessTopic($enrollment, $topic));
        }
        foreach ($topicsByLesson[$lesson2->id] as $topic) {
            $this->assertFalse($this->access->canAccessTopic($enrollment, $topic));
        }
    }

    public function test_plan_with_lesson_two_only_is_accessible_without_completing_lesson_one(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();
        $lesson2 = $lessons[1];

        $enrollment = $this->enrollWithPlan($course, [
            'lesson_ids' => [$lesson2->id],
        ]);

        $this->assertTrue($this->access->canAccessLesson($enrollment, $lesson2));
        $this->assertFalse($this->access->canAccessLesson($enrollment, $lessons[0]));
    }

    public function test_plan_with_topic_only_does_not_require_previous_topics(): void
    {
        [$course, $lessons, $topicsByLesson] = $this->courseWithTwoLessons();
        $topics = $topicsByLesson[$lessons[0]->id];
        $lateTopic = $topics[count($topics) - 1];

        $enrollment = $this->enrollWithPlan($course, [
            'topic_ids' => [$lateTopic->id],
        ]);

        $this->assertTrue($this->access->canAccessTopic($enrollment, $lateTopic));
        $this->assertTrue($this->access->canAccessLesson($enrollment, $lessons[0]));
        $this->assertFalse($this->access->canAccessTopic($enrollment, $topics[0]));
    }

    public function test_plan_with_quiz_only_grants_direct_quiz_access(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();
        $quiz = Quiz::query()->create([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lessons[1]->id,
            'passing_score' => 50,
            'is_required' => false,
            'title' => ['en' => 'Plan Quiz', 'ar' => 'اختبار'],
        ]);

        $enrollment = $this->enrollWithPlan($course, [
            'quiz_ids' => [$quiz->id],
        ]);

        $this->assertTrue($this->access->canAccessQuiz($enrollment, $quiz));
        $this->assertFalse($this->access->canAccessLesson($enrollment, $lessons[0]));
    }

    public function test_plan_with_interactive_activity_only_grants_direct_access(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();
        $activity = InteractiveActivity::query()->create([
            'uuid' => (string) Str::uuid(),
            'lesson_id' => $lessons[1]->id,
            'title' => ['en' => 'Activity', 'ar' => 'نشاط'],
            'status' => InteractiveActivityStatus::Published,
            'entry_file' => 'index.html',
            'version' => 1,
        ]);

        $enrollment = $this->enrollWithPlan($course, [
            'interactive_activity_ids' => [$activity->id],
        ]);

        $this->assertTrue($this->access->canAccessInteractiveActivity($enrollment, $activity));
        $this->assertFalse($this->access->canAccessLesson($enrollment, $lessons[0]));
    }

    public function test_plan_entitlement_overrides_sequential_progression(): void
    {
        $course = Course::query()->where('slug', 'electricity-basics')->first()
            ?? Course::query()->where('access_type', AccessType::Paid)->where('is_published', true)->firstOrFail();

        $lessons = $course->lessons()->where('is_published', true)->orderBy('sort_order')->get();
        $this->assertGreaterThanOrEqual(2, $lessons->count());

        $second = $lessons->skip(1)->first();
        $enrollment = $this->enrollWithPlan($course, [
            'lesson_ids' => [$second->id],
        ]);

        // Without plan, second lesson would be gated; with plan it must open immediately.
        $this->assertTrue($this->access->canAccessLesson($enrollment, $second));
    }

    public function test_non_plan_paid_enrollment_keeps_sequential_gating(): void
    {
        $course = Course::query()->where('slug', 'electricity-basics')->first()
            ?? Course::query()->where('access_type', AccessType::Paid)->where('is_published', true)->firstOrFail();

        $lessons = $course->lessons()->where('is_published', true)->orderBy('sort_order')->get();
        $this->assertGreaterThanOrEqual(2, $lessons->count());

        $user = User::factory()->create();
        $enrollment = $this->enroll->enroll($user, $course);

        $this->assertNull($enrollment->course_plan_id);
        $this->assertTrue($this->access->canAccessLesson($enrollment, $lessons->first()));
        $this->assertFalse($this->access->canAccessLesson($enrollment, $lessons->skip(1)->first()));
    }

    public function test_free_course_remains_fully_accessible_without_plan(): void
    {
        $course = Course::query()->where('slug', 'intro-to-science')->firstOrFail();
        $user = User::factory()->create();
        $enrollment = $this->enroll->enroll($user, $course);
        $lesson = $course->lessons()->orderBy('sort_order')->firstOrFail();

        $this->assertTrue($this->access->canAccessLesson($enrollment, $lesson));
    }

    public function test_invalid_and_cross_course_entitlement_ids_are_rejected(): void
    {
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $other = Course::query()->where('slug', 'intro-to-science')->firstOrFail();
        $otherLesson = $other->lessons()->firstOrFail();

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'Bad', 'ar' => 'سيء'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->sync->syncPlanEntitlements($plan, [
            'lesson_ids' => [$otherLesson->id],
        ]);
    }

    public function test_updating_plan_replaces_entitlements_atomically(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();
        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'Swap', 'ar' => 'تبديل'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
        ]);

        $this->sync->syncPlanEntitlements($plan, ['lesson_ids' => [$lessons[0]->id]]);
        $this->sync->syncPlanEntitlements($plan, ['lesson_ids' => [$lessons[1]->id]]);

        $this->assertDatabaseMissing('course_plan_entitlements', [
            'course_plan_id' => $plan->id,
            'entitleable_id' => $lessons[0]->id,
        ]);
        $this->assertDatabaseHas('course_plan_entitlements', [
            'course_plan_id' => $plan->id,
            'entitleable_id' => $lessons[1]->id,
        ]);
    }

    public function test_enrollment_snapshots_plan_entitlements_immutably_when_plan_changes(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();
        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'Snap', 'ar' => 'لقطة'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
        ]);
        $this->sync->syncPlanEntitlements($plan, ['lesson_ids' => [$lessons[0]->id]]);

        $user = User::factory()->create();
        $enrollment = $this->enroll->enroll($user, $course, null, $plan);

        $this->sync->syncPlanEntitlements($plan, ['lesson_ids' => [$lessons[1]->id]]);

        $this->assertTrue(
            EnrollmentEntitlement::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('entitleable_id', $lessons[0]->id)
                ->exists()
        );
        $this->assertFalse(
            EnrollmentEntitlement::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('entitleable_id', $lessons[1]->id)
                ->exists()
        );
        $this->assertTrue($this->access->canAccessLesson($enrollment->fresh(['entitlements']), $lessons[0]));
        $this->assertFalse($this->access->canAccessLesson($enrollment->fresh(['entitlements']), $lessons[1]));
    }

    public function test_paid_second_plan_purchase_upgrades_existing_enrollment_snapshot(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();

        $planA = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'A', 'ar' => 'أ'],
            'price' => 50,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
        ]);
        $planB = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'B', 'ar' => 'ب'],
            'price' => 80,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
        ]);
        $this->sync->syncPlanEntitlements($planA, ['lesson_ids' => [$lessons[0]->id]]);
        $this->sync->syncPlanEntitlements($planB, ['lesson_ids' => [$lessons[1]->id]]);

        $user = User::factory()->create();

        $orderA = Order::query()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'order_type' => 'course',
            'subtotal' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 50,
            'currency' => 'EGP',
            'billing_address' => [],
            'shipping_address' => [],
        ]);
        $itemA = $orderA->items()->create([
            'product_id' => null,
            'product_name' => 'Plan A',
            'product_sku' => 'PLAN-A',
            'quantity' => 1,
            'unit_price' => 50,
            'total_price' => 50,
            'metadata' => [
                'course_id' => $course->id,
                'course_plan_id' => $planA->id,
            ],
        ]);

        $enrollment = $this->enroll->enroll($user, $course, $itemA->id, $planA);
        $this->assertSame($planA->id, $enrollment->course_plan_id);
        $this->assertTrue($this->access->canAccessLesson($enrollment->fresh(['entitlements']), $lessons[0]));

        $orderB = Order::query()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'order_type' => 'course',
            'subtotal' => 80,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 80,
            'currency' => 'EGP',
            'billing_address' => [],
            'shipping_address' => [],
        ]);
        $itemB = $orderB->items()->create([
            'product_id' => null,
            'product_name' => 'Plan B',
            'product_sku' => 'PLAN-B',
            'quantity' => 1,
            'unit_price' => 80,
            'total_price' => 80,
            'metadata' => [
                'course_id' => $course->id,
                'course_plan_id' => $planB->id,
            ],
        ]);

        $upgraded = $this->enroll->enroll($user, $course, $itemB->id, $planB);

        $this->assertSame($enrollment->id, $upgraded->id);
        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame($planB->id, $upgraded->course_plan_id);
        $this->assertTrue($this->access->canAccessLesson($upgraded->fresh(['entitlements']), $lessons[1]));
        $this->assertFalse($this->access->canAccessLesson($upgraded->fresh(['entitlements']), $lessons[0]));
    }

    public function test_empty_active_plan_is_rejected_and_empty_plan_enrollment_denies_access(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();
        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'Empty', 'ar' => 'فارغ'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
        ]);

        try {
            $this->sync->syncPlanEntitlements($plan, []);
            $this->fail('Expected DomainException for empty active plan');
        } catch (DomainException $e) {
            $this->assertStringContainsString('at least one entitlement', $e->getMessage());
        }

        // Simulate legacy empty snapshot: plan controls access, deny by default.
        $user = User::factory()->create();
        $enrollment = Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'course_plan_id' => $plan->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
            'started_at' => now(),
            'progress_percent' => 0,
            'grant_certificate' => false,
        ]);

        $this->assertTrue(app(CoursePlanAccessService::class)->usesPlanEntitlements($enrollment));
        $this->assertFalse($this->access->canAccessLesson($enrollment, $lessons[0]));
    }

    public function test_plans_api_exposes_entitlement_ids(): void
    {
        [$course, $lessons] = $this->courseWithTwoLessons();
        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'Exposed', 'ar' => 'ظاهر'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'sort_order' => 1,
        ]);
        $this->sync->syncPlanEntitlements($plan, ['lesson_ids' => [$lessons[0]->id]]);

        $this->getJson('/api/v1/courses/'.$course->slug.'/plans')
            ->assertOk()
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonPath('data.0.lesson_ids.0', $lessons[0]->id);
    }

    /**
     * @return array{0: Course, 1: list<Lesson>, 2: array<int, list<Topic>>}
     */
    private function courseWithTwoLessons(): array
    {
        $course = Course::query()->create([
            'slug' => 'plan-access-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Plan Access Course', 'ar' => 'دورة'],
        ]);

        $lessons = [];
        $topicsByLesson = [];

        for ($i = 1; $i <= 2; $i++) {
            $lesson = Lesson::query()->create([
                'course_id' => $course->id,
                'slug' => "lesson-{$i}-".uniqid(),
                'lesson_type' => 'theory',
                'sort_order' => $i,
                'is_published' => true,
                'title' => ['en' => "Lesson {$i}", 'ar' => "درس {$i}"],
            ]);
            $lessons[] = $lesson;
            $topicsByLesson[$lesson->id] = [];

            for ($t = 1; $t <= 3; $t++) {
                $topic = Topic::query()->create([
                    'lesson_id' => $lesson->id,
                    'slug' => "topic-{$i}-{$t}-".uniqid(),
                    'content_type' => 'text',
                    'sort_order' => $t,
                    'is_published' => true,
                    'title' => ['en' => "Topic {$t}", 'ar' => "موضوع {$t}"],
                ]);
                $topicsByLesson[$lesson->id][] = $topic;
            }
        }

        return [$course, $lessons, $topicsByLesson];
    }

    /**
     * @param  array{
     *   lesson_ids?: list<int>,
     *   topic_ids?: list<int>,
     *   quiz_ids?: list<int>,
     *   interactive_activity_ids?: list<int>
     * }  $selection
     */
    private function enrollWithPlan(Course $course, array $selection): Enrollment
    {
        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['en' => 'Test Plan', 'ar' => 'خطة'],
            'price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'max_quiz_attempts' => 3,
        ]);
        $this->sync->syncPlanEntitlements($plan, $selection);

        $user = User::factory()->create();

        return $this->enroll->enroll($user, $course, null, $plan)->load(['entitlements', 'coursePlan', 'course']);
    }
}
