<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression: paid course public details/enroll must not explode with raw
 * ModelNotFoundException when courses.product_id is null/stale.
 */
final class MicroscopeCourseEnrollProductGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_course_details_return_null_price_when_product_missing(): void
    {
        $course = $this->paidCourseWithoutProduct(slug: 'microscope-course');

        $response = $this->getJson('/api/v1/courses/microscope-course')->assertOk();
        $response->assertJsonPath('data.id', $course->id);
        $response->assertJsonPath('data.access_type', 'paid');
        $this->assertNull($response->json('data.price'));
    }

    public function test_paid_course_enroll_without_product_returns_controlled_422(): void
    {
        $this->paidCourseWithoutProduct(slug: 'microscope-course');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/courses/microscope-course/enroll')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ENROLLMENT_FAILED')
            ->assertJsonFragment(['message' => 'لا يمكن الالتحاق بهذه الدورة حاليًا لأن منتج الشراء غير مرتبط.']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_paid_course_with_valid_product_does_not_create_enrollment_directly(): void
    {
        $course = $this->paidCourseWithoutProduct(slug: 'microscope-course');
        $product = Product::query()->create([
            'sku' => 'COURSE-ACCESS-001',
            'slug' => 'microscope-course-access',
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 3720,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['ar' => 'كورس الميكروسكوب', 'en' => 'Microscope Course'],
        ]);
        $course->update(['product_id' => $product->id]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/courses/microscope-course/enroll')
            ->assertStatus(402)
            ->assertJsonPath('code', 'PAYMENT_REQUIRED');

        $this->assertNotEmpty($response->json('data.order.id'));
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'status' => 'awaiting_payment',
        ]);
    }

    public function test_already_enrolled_customer_gets_already_enrolled_without_new_order(): void
    {
        $course = $this->paidCourseWithoutProduct(slug: 'microscope-course');
        $user = User::factory()->create();
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 10,
            'enrolled_at' => now(),
            'started_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/courses/microscope-course/enroll')
            ->assertOk()
            ->assertJsonPath('data.already_enrolled', true);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->count());
    }

    public function test_stale_product_id_returns_controlled_422_not_model_not_found(): void
    {
        $course = $this->paidCourseWithoutProduct(slug: 'microscope-course');
        $course->update(['product_id' => 999999]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/courses/microscope-course/enroll')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ENROLLMENT_FAILED')
            ->assertJsonFragment(['message' => 'لا يمكن الالتحاق بهذه الدورة حاليًا لأن منتج الشراء غير متاح.']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_bearer_customer_wins_over_admin_web_session_on_enroll_guard(): void
    {
        $this->paidCourseWithoutProduct(slug: 'microscope-course');
        $admin = User::factory()->create(['email' => 'admin-enroll-guard@example.com']);
        $customer = User::factory()->create(['email' => 'customer-enroll-guard@example.com']);
        $token = $customer->createToken('api')->plainTextToken;

        $this->actingAs($admin, 'web');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/courses/microscope-course/enroll')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ENROLLMENT_FAILED');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], config('sanctum.guard'));
    }

    private function paidCourseWithoutProduct(string $slug): Course
    {
        return Course::query()->create([
            'slug' => $slug,
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'product_id' => null,
            'title' => ['ar' => 'كورس الميكروسكوب', 'en' => 'Microscope Course'],
            'short_description' => ['ar' => 'م', 'en' => 's'],
            'description' => ['ar' => 'و', 'en' => 'd'],
        ]);
    }
}
