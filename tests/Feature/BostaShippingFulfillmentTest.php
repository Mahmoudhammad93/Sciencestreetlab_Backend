<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\BostaShipmentService;
use App\Modules\Commerce\Application\Services\BostaWebhookService;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Services\OrderFulfillmentService;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class BostaShippingFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'bosta.webhook_secret' => 'test-secret',
            'bosta.api_contract_ready' => false,
            'bosta.webhook_signature_ready' => false,
        ]);
    }

    public function test_checkout_creates_idempotent_bosta_shipment_for_kit(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $cart = $this->cartWithProduct($user, $product);

        $order = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart,
            ['first_name' => $user->name, 'email' => $user->email],
            ['city' => 'Cairo', 'phone' => '01000000000'],
        );

        $this->assertTrue($order->requires_delivery_fulfillment);
        $this->assertDatabaseCount('shipments', 1);
        $shipment = $order->bostaShipment;
        $this->assertNotNull($shipment);
        $this->assertSame('fake-bosta-'.$order->id, $shipment->external_shipment_id);

        app(BostaShipmentService::class)->ensureShipmentForOrder($order->fresh(['items']));
        $this->assertDatabaseCount('shipments', 1);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_online_payment_does_not_enroll_until_bosta_delivered(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->fulfilled_at);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        Mail::assertNothingSent();

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'Delivered',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered->value, $order->status);
        $this->assertNotNull($order->fulfilled_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        Mail::assertSent(OrderConfirmationMail::class, 1);
    }

    public function test_admin_shipped_does_not_unlock_bosta_order(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        app(OrderFulfillmentService::class)->applyAdminUpdate($order, [
            'status' => OrderStatus::Shipped->value,
        ]);

        $order->refresh();
        $this->assertSame(OrderStatus::Shipped->value, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->fulfilled_at);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_duplicate_delivered_webhook_is_idempotent(): void
    {
        Mail::fake();

        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        $service = app(BostaWebhookService::class);
        $service->handle(['external_shipment_id' => $externalId, 'status' => 'delivered']);
        $service->handle(['external_shipment_id' => $externalId, 'status' => 'delivered']);

        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame(ShipmentStatus::Delivered, $order->bostaShipment->fresh()->status);
        $this->assertNotNull($order->fresh()->fulfilled_at);
        Mail::assertSent(OrderConfirmationMail::class, 1);
    }

    public function test_invalid_webhook_secret_is_rejected(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'delivered',
        ], ['X-Bosta-Test-Secret' => 'wrong'])
            ->assertUnauthorized();

        $this->assertNull($order->fresh()->fulfilled_at);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_non_bosta_course_product_still_enrolls_on_payment(): void
    {
        config(['bosta.enabled' => true]);

        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'digital-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Digital', 'ar' => 'رقمي'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'DIG-'.uniqid(),
            'slug' => 'dig-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Course', 'ar' => 'دورة'],
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::AwaitingPayment->value,
            'subtotal' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'currency' => 'EGP',
            'billing_address' => [],
            'shipping_address' => [],
            'requires_delivery_fulfillment' => false,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Course',
            'product_sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'metadata' => ['product_type' => 'course', 'course_id' => $course->id],
        ]);

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => 100,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        $this->assertNotNull($order->fresh()->fulfilled_at);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $this->assertDatabaseCount('shipments', 0);
    }

    /**
     * @return array{0: User, 1: Course, 2: Product}
     */
    private function kitWithCourse(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'kit-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'KIT-'.uniqid(),
            'slug' => 'kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 250,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Science Kit', 'ar' => 'عدة'],
        ]);

        return [$user, $course, $product];
    }

    private function cartWithProduct(User $user, Product $product): Cart
    {
        $cart = Cart::query()->create(['user_id' => $user->id]);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]);

        return $cart->fresh(['items.product']);
    }

    private function checkoutKit(User $user, Product $product): Order
    {
        return app(CheckoutService::class)->createOrderFromCart(
            $user,
            $this->cartWithProduct($user, $product),
            ['first_name' => $user->name, 'email' => $user->email],
            ['city' => 'Cairo'],
        );
    }
}
