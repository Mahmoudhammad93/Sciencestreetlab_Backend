<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\OrderFulfillmentService;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class CodOrderFulfillmentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pending_to_paid_grants_course_enrollment(): void
    {
        [$user, $course, $order] = $this->codOrderWithCourseProduct();

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        app(OrderFulfillmentService::class)->applyAdminUpdate($order, [
            'status' => OrderStatus::Paid->value,
        ]);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid->value, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
    }

    public function test_admin_pending_to_shipped_grants_course_enrollment(): void
    {
        [$user, $course, $order] = $this->codOrderWithCourseProduct();

        app(OrderFulfillmentService::class)->applyAdminUpdate($order, [
            'status' => OrderStatus::Shipped->value,
        ]);

        $order->refresh();
        $this->assertSame(OrderStatus::Shipped->value, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_admin_fulfillment_with_course_plan_sets_plan_and_entitlements(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $lesson = $course->lessons()->firstOrFail();

        $plan = CoursePlan::query()->create([
            'course_id' => $course->id,
            'name' => ['ar' => 'COD', 'en' => 'COD Plan'],
            'price' => 150,
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

        $product = Product::query()->create([
            'sku' => 'COD-PLAN-'.uniqid(),
            'slug' => 'cod-plan-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 150,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'course_plan_id' => $plan->id,
            'published_at' => now(),
            'name' => ['en' => 'COD Plan Product', 'ar' => 'منتج'],
        ]);

        $order = $this->makeCodOrder($user, $product);

        app(OrderFulfillmentService::class)->applyAdminUpdate($order, [
            'status' => OrderStatus::Paid->value,
        ]);

        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->firstOrFail();

        $this->assertSame($plan->id, $enrollment->course_plan_id);
        $this->assertDatabaseHas('enrollment_entitlements', [
            'enrollment_id' => $enrollment->id,
            'entitleable_id' => $lesson->id,
        ]);
    }

    public function test_saving_paid_order_again_does_not_duplicate_enrollment_or_refire_event(): void
    {
        [$user, $course, $order] = $this->codOrderWithCourseProduct();
        $fulfillment = app(OrderFulfillmentService::class);

        $fulfillment->applyAdminUpdate($order, ['status' => OrderStatus::Paid->value]);
        $paidAt = $order->fresh()->paid_at;

        Event::fake([OrderPaid::class]);

        $fulfillment->applyAdminUpdate($order->fresh(), ['status' => OrderStatus::Paid->value]);
        $fulfillment->applyAdminUpdate($order->fresh(), ['status' => OrderStatus::Shipped->value]);

        Event::assertNotDispatched(OrderPaid::class);

        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertTrue($order->fresh()->paid_at->equalTo($paidAt));
        $this->assertSame(OrderStatus::Shipped->value, $order->fresh()->status);
    }

    public function test_online_payment_still_grants_enrollment_via_payment_completion(): void
    {
        [$user, $course, $order] = $this->codOrderWithCourseProduct();

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        $this->assertSame(OrderStatus::Paid->value, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_product_without_course_does_not_create_enrollment_on_admin_paid(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'sku' => 'KIT-'.uniqid(),
            'slug' => 'kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 50,
            'currency' => 'EGP',
            'course_id' => null,
            'published_at' => now(),
            'name' => ['en' => 'Kit', 'ar' => 'عدة'],
        ]);

        $order = $this->makeCodOrder($user, $product);
        app(OrderFulfillmentService::class)->applyAdminUpdate($order, [
            'status' => OrderStatus::Paid->value,
        ]);

        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(0, Enrollment::query()->where('user_id', $user->id)->count());
    }

    public function test_payment_completion_is_idempotent_and_uses_fulfillment_service(): void
    {
        [$user, $course, $order] = $this->codOrderWithCourseProduct();

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        $service = app(PaymentCompletionService::class);
        $service->complete($payment);
        $service->complete($payment->fresh());

        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame(PaymentStatus::Completed->value, $payment->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Course, 2: Order}
     */
    private function codOrderWithCourseProduct(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'cod-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'COD Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'COD-'.uniqid(),
            'slug' => 'cod-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 200,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'COD Product', 'ar' => 'منتج'],
        ]);

        return [$user, $course, $this->makeCodOrder($user, $product)];
    }

    private function makeCodOrder(User $user, Product $product): Order
    {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::AwaitingPayment->value,
            'subtotal' => $product->price,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => $product->price,
            'currency' => 'EGP',
            'billing_address' => ['first_name' => $user->name, 'email' => $user->email],
            'shipping_address' => ['city' => 'Cairo'],
            'notes' => 'COD',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->getTranslation('name', 'en'),
            'product_sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => $product->price,
            'total_price' => $product->price,
            'metadata' => [
                'course_id' => $product->course_id,
                'course_plan_id' => $product->course_plan_id,
            ],
        ]);

        return $order->fresh(['items']);
    }
}
