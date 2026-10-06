<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
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
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\FakeBostaClient;
use App\Modules\Commerce\Mail\OrderDeliveredMail;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminDeliveredFulfillmentTest extends TestCase
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

        FakeBostaClient::clearDeliveryStates();
        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_delivered_on_bosta_gated_guest_runs_fulfillment_and_activation_mail(): void
    {
        Mail::fake();

        $order = $this->guestKitCheckout();
        $this->assertTrue((bool) $order->requires_delivery_fulfillment);
        $this->assertNull($order->fulfilled_at);
        $this->assertNull($order->user_id);

        // Simulate prior split-brain: status saved as delivered without lifecycle.
        $order->update(['status' => OrderStatus::Delivered->value]);
        $this->assertNull($order->fresh()->fulfilled_at);

        app(OrderFulfillmentService::class)->fulfillFromAdminDelivery($order->fresh());

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered->value, $order->status);
        $this->assertNotNull($order->fulfilled_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertNotNull($order->paid_at);
        $this->assertDatabaseCount('guest_purchase_claims', 1);
        Mail::assertSent(OrderDeliveredMail::class, 1);

        // Admin delivery must not invent Bosta provider Delivered.
        $this->assertNotSame(ShipmentStatus::Delivered, $order->bostaShipment?->fresh()?->status);
    }

    public function test_apply_admin_update_delivered_uses_same_fulfillment_service(): void
    {
        Mail::fake();
        $order = $this->guestKitCheckout();

        app(OrderFulfillmentService::class)->applyAdminUpdate($order, [
            'status' => OrderStatus::Delivered->value,
        ]);

        $order->refresh();
        $this->assertNotNull($order->fulfilled_at);
        $this->assertDatabaseCount('guest_purchase_claims', 1);
        Mail::assertSent(OrderDeliveredMail::class, 1);
    }

    public function test_admin_shipped_still_does_not_fulfill_bosta_gated_order(): void
    {
        Mail::fake();
        $order = $this->guestKitCheckout();

        app(OrderFulfillmentService::class)->applyAdminUpdate($order, [
            'status' => OrderStatus::Shipped->value,
        ]);

        $order->refresh();
        $this->assertSame(OrderStatus::Shipped->value, $order->status);
        $this->assertNull($order->fulfilled_at);
        $this->assertDatabaseCount('guest_purchase_claims', 0);
        Mail::assertNotSent(OrderDeliveredMail::class);
    }

    public function test_admin_delivered_twice_is_idempotent(): void
    {
        Mail::fake();
        $order = $this->guestKitCheckout();
        $svc = app(OrderFulfillmentService::class);

        $svc->fulfillFromAdminDelivery($order);
        $fulfilledAt = $order->fresh()->fulfilled_at;
        $svc->fulfillFromAdminDelivery($order->fresh());
        $svc->applyAdminUpdate($order->fresh(), ['status' => OrderStatus::Delivered->value]);

        $this->assertTrue($order->fresh()->fulfilled_at->equalTo($fulfilledAt));
        $this->assertDatabaseCount('guest_purchase_claims', 1);
        Mail::assertSent(OrderDeliveredMail::class, 1);
    }

    public function test_bosta_webhook_then_admin_delivered_is_idempotent(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->userKitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);
        $externalId = $order->bostaShipment->external_shipment_id;

        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $externalId,
            'status' => 'delivered',
        ]);

        $fulfilledAt = $order->fresh()->fulfilled_at;
        $this->assertNotNull($fulfilledAt);

        app(OrderFulfillmentService::class)->fulfillFromAdminDelivery($order->fresh());

        $this->assertTrue($order->fresh()->fulfilled_at->equalTo($fulfilledAt));
        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        Mail::assertSent(OrderDeliveredMail::class, 1);
    }

    public function test_admin_then_bosta_delivered_is_idempotent_and_does_not_duplicate_mail(): void
    {
        Mail::fake();
        [$user, $course, $product] = $this->userKitWithCourse();
        $order = $this->checkoutAndPayKit($user, $product);

        app(OrderFulfillmentService::class)->fulfillFromAdminDelivery($order->fresh());
        $this->assertNotNull($order->fresh()->fulfilled_at);
        Mail::assertSent(OrderDeliveredMail::class, 1);

        app(BostaWebhookService::class)->handle([
            'external_shipment_id' => $order->bostaShipment->external_shipment_id,
            'status' => 'delivered',
        ]);

        $this->assertSame(1, Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count());
        Mail::assertSent(OrderDeliveredMail::class, 1);
    }

    public function test_existing_user_guest_email_is_claimed_without_duplicate_user(): void
    {
        Mail::fake();
        $existing = User::factory()->create(['email' => 'existing-guest@example.com']);
        $order = $this->guestKitCheckout('existing-guest@example.com');

        app(OrderFulfillmentService::class)->fulfillFromAdminDelivery($order->fresh());

        $order->refresh();
        $this->assertSame($existing->id, $order->user_id);
        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['existing-guest@example.com'])->count());
        $this->assertSame(1, Enrollment::query()->where('user_id', $existing->id)->count());
        Mail::assertSent(OrderDeliveredMail::class, 1);
    }

    public function test_filament_mark_delivered_action_resolves_and_is_visible_for_unfulfilled_gated_order(): void
    {
        $order = $this->guestKitCheckout();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful()
            ->assertActionExists('markDelivered')
            ->assertActionVisible('markDelivered');
    }

    public function test_filament_mark_delivered_action_runs_fulfillment_lifecycle(): void
    {
        Mail::fake();
        $order = $this->guestKitCheckout();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('markDelivered')
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertNotNull($order->fulfilled_at);
        $this->assertSame(OrderStatus::Delivered->value, $order->status);
        Mail::assertSent(OrderDeliveredMail::class, 1);
    }

    public function test_physical_kit_without_course_fulfills_without_activation_mail(): void
    {
        Mail::fake();
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

        $this->withHeader('X-Cart-Session', 'adminphys01')
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'adminphys01')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->address(),
                'shipping_address' => $this->address(),
                'payment_method' => 'cash_on_delivery',
            ])->assertCreated();

        $order = Order::query()->findOrFail((int) $checkout->json('data.id'));
        app(OrderFulfillmentService::class)->fulfillFromAdminDelivery($order);

        $this->assertNotNull($order->fresh()->fulfilled_at);
        Mail::assertNotSent(OrderDeliveredMail::class);
        $this->assertSame(0, Enrollment::query()->count());
    }

    private function guestKitCheckout(string $email = 'guest-admin-delivered@example.com'): Order
    {
        [, , $product] = $this->userKitWithCourse();

        $session = 'gadmin'.substr(md5($email.microtime(true)), 0, 8);
        $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->address(['email' => $email]),
                'shipping_address' => $this->address(['email' => $email]),
                'payment_method' => 'cash_on_delivery',
            ])->assertCreated();

        return Order::query()->with(['items', 'bostaShipment'])->findOrFail((int) $checkout->json('data.id'));
    }

    /**
     * @return array{0: User, 1: Course, 2: Product}
     */
    private function userKitWithCourse(): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'admin-del-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'KIT-AD-'.uniqid(),
            'slug' => 'kit-ad-'.uniqid(),
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
            'email' => 'guest-admin-delivered@example.com',
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
