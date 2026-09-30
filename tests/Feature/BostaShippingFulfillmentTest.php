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
            ['first_name' => $user->name, 'last_name' => 'User', 'email' => $user->email, 'phone' => '01012345678', 'city' => 'Cairo', 'country' => 'EG'],
            ['first_name' => $user->name, 'last_name' => 'User', 'email' => $user->email, 'phone' => '01012345678', 'city' => 'Cairo', 'country' => 'EG'],
        )['order'];

        $this->assertTrue($order->requires_delivery_fulfillment);
        $this->assertDatabaseCount('shipments', 1);
        $shipment = $order->bostaShipment;
        $this->assertNotNull($shipment);
        $this->assertSame('fake-bosta-'.$order->id, $shipment->external_shipment_id);
        $this->assertSame('TRK-'.$order->order_number, $shipment->tracking_number);
        $this->assertSame(ShipmentStatus::Created, $shipment->status);
        $this->assertTrue((bool) data_get($shipment->metadata, 'raw.test_mode'));

        app(BostaShipmentService::class)->ensureShipmentForOrder($order->fresh(['items']));
        $this->assertDatabaseCount('shipments', 1);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_fake_mode_does_not_require_api_key_and_uses_fake_client(): void
    {
        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'bosta.api_key' => null,
            'bosta.api_url' => null,
            'bosta.api_contract_ready' => false,
        ]);

        $this->assertInstanceOf(
            \App\Modules\Commerce\Infrastructure\Shipping\Bosta\FakeBostaClient::class,
            app(\App\Modules\Commerce\Domain\Contracts\BostaClientInterface::class)
        );

        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $this->assertNotNull($order->bostaShipment?->external_shipment_id);
        $this->assertStringStartsWith('fake-bosta-', $order->bostaShipment->external_shipment_id);
    }

    public function test_digital_only_course_order_does_not_create_bosta_shipment(): void
    {
        config(['bosta.enabled' => true, 'bosta.use_fake' => true]);

        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'digital-only-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Digital', 'ar' => 'رقمي'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'DIG-ONLY-'.uniqid(),
            'slug' => 'dig-only-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Course', 'ar' => 'دورة'],
        ]);

        $order = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $this->cartWithProduct($user, $product),
            ['first_name' => $user->name, 'last_name' => 'User', 'email' => $user->email, 'phone' => '01012345678', 'city' => 'Cairo', 'country' => 'EG'],
            null,
        )['order'];

        $this->assertFalse($order->requires_delivery_fulfillment);
        $this->assertNull($order->bostaShipment);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_bosta_disabled_skips_shipment_creation(): void
    {
        config(['bosta.enabled' => false, 'bosta.use_fake' => true]);

        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $this->assertFalse($order->requires_delivery_fulfillment);
        $this->assertNull($order->bostaShipment);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_production_refuses_fake_bosta_client_binding(): void
    {
        config(['bosta.use_fake' => true, 'bosta.enabled' => true]);
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BOSTA_USE_FAKE=true is forbidden when APP_ENV=production');

        app(\App\Modules\Commerce\Domain\Contracts\BostaClientInterface::class);
    }

    public function test_http_bosta_client_is_not_used_in_fake_mode(): void
    {
        config(['bosta.enabled' => true, 'bosta.use_fake' => true, 'bosta.api_key' => null]);

        $httpResolved = false;
        $this->app->bind(
            \App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient::class,
            function () use (&$httpResolved) {
                $httpResolved = true;
                throw new \RuntimeException('HttpBostaClient must not be resolved in fake mode');
            }
        );

        $this->assertInstanceOf(
            \App\Modules\Commerce\Infrastructure\Shipping\Bosta\FakeBostaClient::class,
            app(\App\Modules\Commerce\Domain\Contracts\BostaClientInterface::class)
        );

        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $this->assertFalse($httpResolved, 'HttpBostaClient must not be resolved when BOSTA_USE_FAKE=true');
        $this->assertSame('fake-bosta-'.$order->id, $order->bostaShipment?->external_shipment_id);
        $this->assertSame(ShipmentStatus::Created, $order->bostaShipment?->status);
    }

    public function test_admin_delivered_transition_unlocks_course_after_payment(): void
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
        $this->assertNull($order->fulfilled_at);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        // Simulate Filament admin "Update Bosta shipping" → Delivered
        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'Out for Delivery',
        ]);
        $this->assertNull($order->fresh()->fulfilled_at);

        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'Delivered',
        ]);

        $order->refresh();
        $this->assertNotNull($order->fulfilled_at);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
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

    public function test_webhook_route_requires_no_user_login(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        $this->assertGuest();

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'in_transit',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'in_transit');
    }

    public function test_malformed_webhook_payload_is_rejected(): void
    {
        $this->postJson('/api/v1/webhooks/bosta', [
            'status' => 'delivered',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bosta webhook missing shipment identifier.');

        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_unknown_shipment_does_not_create_data(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $this->checkoutKit($user, $product);
        $before = \App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment::query()->count();

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => 'totally-unknown-bosta-id',
            'status' => 'delivered',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('outcome', 'ignored_unknown_shipment');

        $this->assertSame($before, \App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment::query()->count());
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame(0, Order::query()->whereNotNull('fulfilled_at')->count());
    }

    public function test_unknown_bosta_status_does_not_fulfill(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $externalId,
            'status' => 'weird_new_bosta_code_99',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('outcome', 'ignored_unknown_status')
            ->assertJsonPath('status', 'created');

        $order->refresh();
        $this->assertNull($order->fulfilled_at);
        $this->assertSame(ShipmentStatus::Created, $order->bostaShipment->fresh()->status);
        $this->assertSame('weird_new_bosta_code_99', $order->bostaShipment->fresh()->provider_status);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        Mail::assertNothingSent();
    }

    public function test_non_delivered_statuses_do_not_grant_course_access(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        foreach (['picked_up', 'in_transit', 'out_for_delivery'] as $status) {
            $this->postJson('/api/v1/webhooks/bosta', [
                'external_shipment_id' => $externalId,
                'status' => $status,
            ], ['X-Bosta-Test-Secret' => 'test-secret'])
                ->assertOk()
                ->assertJsonPath('status', $status);

            $this->assertNull($order->fresh()->fulfilled_at);
            $this->assertDatabaseMissing('enrollments', [
                'user_id' => $user->id,
                'course_id' => $course->id,
            ]);
        }

        Mail::assertNothingSent();
    }

    public function test_cancelled_returned_failed_statuses_do_not_fulfill(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        foreach ([
            'cancelled' => ShipmentStatus::Cancelled,
            'returned' => ShipmentStatus::Failed,
            'rejected' => ShipmentStatus::Failed,
            'failed' => ShipmentStatus::Failed,
        ] as $providerStatus => $expected) {
            // Fresh kit each terminal attempt after first would stick terminal — recreate.
            if ($providerStatus !== 'cancelled') {
                [$user, $course, $product] = $this->kitWithCourse();
                $order = $this->checkoutKit($user, $product);
                $externalId = $order->bostaShipment->external_shipment_id;
            }

            $this->postJson('/api/v1/webhooks/bosta', [
                'external_shipment_id' => $externalId,
                'status' => $providerStatus,
            ], ['X-Bosta-Test-Secret' => 'test-secret'])
                ->assertOk()
                ->assertJsonPath('status', $expected->value);

            $this->assertNull($order->fresh()->fulfilled_at);
            $this->assertDatabaseMissing('enrollments', [
                'user_id' => $user->id,
                'course_id' => $course->id,
            ]);
        }

        Mail::assertNothingSent();
    }

    public function test_out_of_order_event_cannot_regress_delivered_state(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $externalId,
            'status' => 'delivered',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $externalId,
            'status' => 'in_transit',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('status', 'delivered')
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('outcome', 'duplicate');

        $shipment = $order->bostaShipment->fresh();
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        Mail::assertSent(OrderConfirmationMail::class, 1);
    }

    public function test_terminal_failure_is_not_overwritten_by_mid_journey_or_delivered(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $externalId,
            'status' => 'failed',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $externalId,
            'status' => 'in_transit',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('outcome', 'ignored_terminal_regression');

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $externalId,
            'status' => 'delivered',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('outcome', 'ignored_terminal_regression');

        $this->assertNull($order->fresh()->fulfilled_at);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        Mail::assertNothingSent();
    }

    public function test_configured_verifier_rejects_until_official_signature_ready(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutKit($user, $product);

        config([
            'bosta.webhook_signature_ready' => false,
            'bosta.webhook_secret' => 'real-looking-secret',
        ]);

        // Keep FakeBostaClient for shipment create; swap only webhook verifier to production stub.
        $this->app->instance(
            \App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface::class,
            $this->app->make(\App\Modules\Commerce\Infrastructure\Shipping\Bosta\ConfiguredBostaWebhookVerifier::class)
        );

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'delivered',
        ], ['X-Bosta-Test-Secret' => 'real-looking-secret'])
            ->assertUnauthorized();

        $this->assertNull($order->fresh()->fulfilled_at);
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
            ['first_name' => $user->name, 'last_name' => 'User', 'email' => $user->email, 'phone' => '01012345678', 'city' => 'Cairo', 'country' => 'EG'],
            ['first_name' => $user->name, 'last_name' => 'User', 'email' => $user->email, 'phone' => '01012345678', 'city' => 'Cairo', 'country' => 'EG'],
        )['order'];
    }
}
