<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\BostaShipmentReconciliationService;
use App\Modules\Commerce\Application\Services\BostaWebhookService;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\FakeBostaClient;
use App\Modules\Commerce\Mail\OrderDeliveredMail;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class BostaShipmentReconciliationTest extends TestCase
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
            'bosta.reconcile_lookback_days' => 30,
            'bosta.reconcile_max_per_run' => 100,
        ]);

        FakeBostaClient::clearDeliveryStates();
        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
    }

    public function test_api_reconciliation_delivered_fulfills_like_webhook(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        $shipment = $order->bostaShipment;
        $this->assertNotNull($shipment);
        $this->assertNull($order->fulfilled_at);

        FakeBostaClient::setDeliveryState($shipment->external_shipment_id, 45, 'SEND');

        $result = app(BostaShipmentReconciliationService::class)
            ->reconcileShipment($shipment->fresh(), 'reconciliation');

        $this->assertTrue($result->fulfilled);
        $this->assertSame(ShipmentStatus::Delivered, $result->shipment?->status);
        $this->assertNotNull($order->fresh()->fulfilled_at);
        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        Mail::assertSent(OrderDeliveredMail::class, 1);
        $this->assertSame('reconciliation', $result->shipment?->metadata['last_status_source'] ?? null);
    }

    public function test_repeated_reconciliation_is_idempotent(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;
        FakeBostaClient::setDeliveryState($externalId, 45, 'SEND');

        $service = app(BostaShipmentReconciliationService::class);
        $service->reconcileShipment($order->bostaShipment->fresh(), 'reconciliation');
        $service->reconcileShipment($order->bostaShipment->fresh(), 'reconciliation');

        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame(1, Order::query()->whereKey($order->id)->whereNotNull('fulfilled_at')->count());
        Mail::assertSent(OrderDeliveredMail::class, 1);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_stale_in_transit_after_delivered_is_ignored(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $externalId,
            'state' => 45,
            'type' => 'SEND',
        ]);

        $result = app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $externalId,
            'state' => 30,
            'type' => 'SEND',
        ]);

        $this->assertTrue($result->duplicate || $result->outcome === 'ignored_terminal_regression');
        $this->assertSame(ShipmentStatus::Delivered, $order->bostaShipment->fresh()->status);
        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
    }

    public function test_progress_regression_out_for_delivery_to_in_transit_ignored(): void
    {
        [$user, , $product] = $this->kitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $externalId,
            'state' => 41,
            'type' => 'SEND',
        ]);
        $this->assertSame(ShipmentStatus::OutForDelivery, $order->bostaShipment->fresh()->status);

        $result = app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $externalId,
            'state' => 30,
            'type' => 'SEND',
        ]);

        $this->assertSame('ignored_terminal_regression', $result->outcome);
        $this->assertSame(ShipmentStatus::OutForDelivery, $order->bostaShipment->fresh()->status);
        $this->assertNull($order->fresh()->fulfilled_at);
    }

    public function test_unknown_bosta_state_from_api_does_not_fulfill(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        FakeBostaClient::setDeliveryState($order->bostaShipment->external_shipment_id, 999, 'SEND');

        $result = app(BostaShipmentReconciliationService::class)->reconcileShipment($order->bostaShipment->fresh());

        $this->assertSame('ignored_unknown_status', $result->outcome);
        $this->assertNull($order->fresh()->fulfilled_at);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_bosta_api_unavailable_leaves_local_unchanged(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        $shipment = $order->bostaShipment;
        $this->assertNotNull($shipment?->external_shipment_id);
        $before = $shipment->status;

        config([
            'bosta.use_fake' => false,
            'bosta.api_key' => 'test-key',
            'bosta.api_url' => 'https://app.bosta.co',
            'bosta.api_contract_ready' => true,
        ]);

        $this->app->forgetInstance(\App\Modules\Commerce\Domain\Contracts\BostaClientInterface::class);
        $this->app->forgetInstance(BostaWebhookService::class);
        $this->app->forgetInstance(BostaShipmentReconciliationService::class);
        $this->app->instance(
            \App\Modules\Commerce\Domain\Contracts\BostaClientInterface::class,
            $this->app->make(\App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient::class),
        );

        Http::fake([
            'https://app.bosta.co/api/v2/deliveries/search' => Http::response(['success' => false, 'message' => 'down'], 503),
        ]);

        try {
            app(BostaShipmentReconciliationService::class)->reconcileShipment($shipment->fresh());
            $this->fail('Expected runtime exception');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Unable to fetch Bosta delivery status', $e->getMessage());
        }

        $this->assertSame($before, $shipment->fresh()->status);
        $this->assertNull($order->fresh()->fulfilled_at);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_reconcile_command_skips_missing_external_id(): void
    {
        [$user, $course, $product] = $this->kitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        FakeBostaClient::setDeliveryState($order->bostaShipment->external_shipment_id, 41, 'SEND');

        // Separate order: UNIQUE(order_id, provider) prevents a second bosta row on $order.
        [$user2, , $product2] = $this->kitWithCourse();
        $order2 = $this->checkoutAndPayKit($user2, $product2);
        $skipped = $order2->bostaShipment;
        $this->assertNotNull($skipped);
        $skipped->update([
            'external_shipment_id' => null,
            'status' => ShipmentStatus::Created,
            'metadata' => array_merge($skipped->metadata ?? [], ['creation_state' => 'failed']),
        ]);

        $exit = Artisan::call('bosta:reconcile-shipments');
        $this->assertSame(0, $exit);
        $this->assertSame(ShipmentStatus::OutForDelivery, $order->bostaShipment->fresh()->status);
        $this->assertSame(ShipmentStatus::Created, $skipped->fresh()->status);
        $this->assertNull($order->fresh()->fulfilled_at);
        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_guest_course_order_reconciliation_delivered_sends_activation_mail(): void
    {
        Mail::fake();
        config(['bosta.webhook_secret' => 'test-secret']);

        $course = Course::query()->create([
            'slug' => 'guest-recon-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Guest Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $kit = Product::query()->create([
            'sku' => 'KIT-G-'.uniqid(),
            'slug' => 'kit-g-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 200,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Kit', 'ar' => 'عدة'],
        ]);

        $this->withHeader('X-Cart-Session', 'guestrecon01')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestrecon01')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->address(),
                'shipping_address' => $this->address(),
            ])->assertCreated();

        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $order = Order::query()->with('bostaShipment')->findOrFail($orderId);
        FakeBostaClient::setDeliveryState($order->bostaShipment->external_shipment_id, 45, 'SEND');

        app(BostaShipmentReconciliationService::class)->reconcileShipment($order->bostaShipment->fresh(), 'reconciliation');

        $this->assertNotNull($order->fresh()->fulfilled_at);
        $this->assertSame(0, Enrollment::query()->count());
        Mail::assertSent(OrderDeliveredMail::class, 1);
        $this->assertDatabaseCount('guest_purchase_claims', 1);
    }

    public function test_physical_kit_without_course_fulfills_without_enrollment(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $product = Product::query()->create([
            'sku' => 'KIT-PHYS-'.uniqid(),
            'slug' => 'kit-phys-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 150,
            'currency' => 'EGP',
            'course_id' => null,
            'published_at' => now(),
            'name' => ['en' => 'Physical Kit', 'ar' => 'عدة'],
        ]);

        $order = $this->checkoutAndPayKit($user, $product);
        FakeBostaClient::setDeliveryState($order->bostaShipment->external_shipment_id, 45, 'SEND');

        $result = app(BostaShipmentReconciliationService::class)
            ->reconcileShipment($order->bostaShipment->fresh(), 'reconciliation');

        $this->assertTrue($result->fulfilled);
        $this->assertNotNull($order->fresh()->fulfilled_at);
        $this->assertSame(0, Enrollment::query()->count());
        // Delivered mail is course-entitlement EMAIL2 only — physical-only kits get fulfillment, not that mail.
        Mail::assertNotSent(OrderDeliveredMail::class);
    }

    /**
     * @return array{0: User, 1: Course, 2: Product}
     */
    private function kitWithCourse(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'recon-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'KIT-R-'.uniqid(),
            'slug' => 'kit-r-'.uniqid(),
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

    private function checkoutAndPayKit(User $user, Product $product): Order
    {
        $address = $this->address(['email' => $user->email, 'first_name' => $user->name]);
        $cart = Cart::query()->create(['user_id' => $user->id]);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]);

        $order = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart->fresh(['items.product']),
            $address,
            $address,
        )['order'];

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);
        app(PaymentCompletionService::class)->complete($payment);

        return $order->fresh(['items', 'bostaShipment']) ?? $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function address(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Guest',
            'last_name' => 'Buyer',
            'email' => 'guest-recon@example.com',
            'phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => '123 Test Street',
            'district' => 'Nasr City',
            'district_name' => 'Nasr City',
            'bosta_district_id' => 'district-nasr',
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
        ], $overrides);
    }
}
