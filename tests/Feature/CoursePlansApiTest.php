<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CoursePlansApiTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Course $otherCourse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $this->otherCourse = Course::query()->create([
            'slug' => 'other-course-' . uniqid(),
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Other Course', 'ar' => 'دورة أخرى'],
        ]);
    }

    public function test_get_course_plans_returns_200(): void
    {
        $this->createPlan(['sort_order' => 1]);

        $this->getJson('/api/v1/courses/microscope-course/plans')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_returns_active_plans_only(): void
    {
        $active = $this->createPlan(['name' => ['en' => 'Active Plan', 'ar' => 'نشط'], 'sort_order' => 1]);
        $this->createPlan([
            'name' => ['en' => 'Inactive Plan', 'ar' => 'غير نشط'],
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/v1/courses/microscope-course/plans')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_returns_only_plans_for_requested_course(): void
    {
        $coursePlan = $this->createPlan(['sort_order' => 1]);
        $otherPlan = CoursePlan::query()->create([
            'course_id' => $this->otherCourse->id,
            'name' => ['en' => 'Other Course Plan', 'ar' => 'خطة'],
            'price' => 50,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/v1/courses/microscope-course/plans')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($coursePlan->id, $ids);
        $this->assertNotContains($otherPlan->id, $ids);
    }

    public function test_returns_plans_ordered_by_sort_order_then_id(): void
    {
        $second = $this->createPlan([
            'name' => ['en' => 'Second', 'ar' => 'ثاني'],
            'sort_order' => 2,
        ]);
        $first = $this->createPlan([
            'name' => ['en' => 'First', 'ar' => 'أول'],
            'sort_order' => 1,
        ]);
        $third = $this->createPlan([
            'name' => ['en' => 'Third', 'ar' => 'ثالث'],
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/v1/courses/microscope-course/plans')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$first->id, $second->id, $third->id], $ids);
    }

    public function test_returns_free_and_paid_plans(): void
    {
        $free = $this->createPlan([
            'name' => ['en' => 'Free Plan', 'ar' => 'مجاني'],
            'price' => 0,
            'sort_order' => 1,
        ]);
        $paid = $this->createPlan([
            'name' => ['en' => 'Paid Plan', 'ar' => 'مدفوع'],
            'price' => 599,
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/v1/courses/microscope-course/plans')->assertOk();
        $plans = collect($response->json('data'))->keyBy('id');

        $this->assertSame('0.00', $plans[$free->id]['price']);
        $this->assertSame('599.00', $plans[$paid->id]['price']);
    }

    public function test_returns_plan_fields_correctly(): void
    {
        $plan = $this->createPlan([
            'name' => ['en' => 'Premium Plan', 'ar' => 'مميز'],
            'description' => ['en' => 'Full access', 'ar' => 'وصول كامل'],
            'price' => 299,
            'currency' => 'EGP',
            'is_lifetime' => false,
            'duration_days' => 30,
            'max_quiz_attempts' => 2,
            'grant_certificate' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/v1/courses/microscope-course/plans')
            ->assertOk()
            ->assertJsonPath('data.0.id', $plan->id)
            ->assertJsonPath('data.0.name', 'Premium Plan')
            ->assertJsonPath('data.0.description', 'Full access')
            ->assertJsonPath('data.0.price', '299.00')
            ->assertJsonPath('data.0.currency', 'EGP')
            ->assertJsonPath('data.0.is_lifetime', false)
            ->assertJsonPath('data.0.duration_days', 30)
            ->assertJsonPath('data.0.max_quiz_attempts', 2)
            ->assertJsonPath('data.0.grant_certificate', true);
    }

    public function test_unpublished_course_returns_404(): void
    {
        $this->course->update(['is_published' => false]);

        $this->getJson('/api/v1/courses/microscope-course/plans')
            ->assertNotFound()
            ->assertJsonPath('message', 'Course not found');
    }

    public function test_invalid_course_slug_returns_404(): void
    {
        $this->getJson('/api/v1/courses/does-not-exist/plans')
            ->assertNotFound()
            ->assertJsonPath('message', 'Course not found');
    }

    public function test_endpoint_works_without_authentication(): void
    {
        $this->createPlan(['sort_order' => 1]);

        $this->getJson('/api/v1/courses/microscope-course/plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPlan(array $overrides = []): CoursePlan
    {
        return CoursePlan::query()->create(array_merge([
            'course_id' => $this->course->id,
            'name' => ['en' => 'Plan', 'ar' => 'خطة'],
            'description' => ['en' => 'Description', 'ar' => 'وصف'],
            'price' => 100,
            'currency' => 'EGP',
            'is_active' => true,
            'is_lifetime' => true,
            'duration_days' => null,
            'max_quiz_attempts' => null,
            'grant_certificate' => false,
            'sort_order' => 0,
        ], $overrides));
    }
}
