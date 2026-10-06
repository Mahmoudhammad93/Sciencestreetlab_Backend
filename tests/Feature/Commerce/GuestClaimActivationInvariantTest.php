<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\GuestPurchaseClaimService;
use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Commerce\Infrastructure\Persistence\Models\GuestPurchaseClaim;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Systemic guest-claim activation invariant + regression matrix.
 */
final class GuestClaimActivationInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'bosta.webhook_secret' => 'test-secret',
            'sciencestreet.frontend_url' => 'https://app.example.test',
        ]);
    }

    public function test_scenario_1_activate_immediately_attaches_and_my_orders_visible(): void
    {
        Mail::fake();
        [$course, $kit] = $this->kitWithCourse();
        $orderId = $this->placePaidGuestKit($kit, 'inv01');
        $raw = $this->forceClaimRaw($orderId, 'scen1claimtokenvalue0123456789abcd');

        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Buyer One',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])
            ->assertOk()
            ->assertJsonPath('data.visible_in_my_orders', true);

        $user = User::query()->where('email', 'guest@example.com')->firstOrFail();
        $this->assertSame($user->id, (int) Order::query()->find($orderId)?->user_id);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonFragment(['order_number' => Order::query()->find($orderId)?->order_number]);
    }

    public function test_scenario_2_pre_delivery_visible_no_enrollment_then_delivered_enrolls_once(): void
    {
        Mail::fake();
        [$course, $kit] = $this->kitWithCourse();
        $orderId = $this->placePaidGuestKit($kit, 'inv02');
        $raw = $this->forceClaimRaw($orderId, 'scen2claimtokenvalue0123456789abcd');

        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Buyer Two',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk();

        $userId = (int) User::query()->where('email', 'guest@example.com')->value('id');
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertNull(Order::query()->find($orderId)?->fulfilled_at);

        $order = Order::query()->with('bostaShipment')->findOrFail($orderId);
        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $order->bostaShipment?->external_shipment_id,
            'status' => 'Delivered',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk();

        $order->refresh();
        $this->assertNotNull($order->fulfilled_at);
        $this->assertSame(1, Enrollment::query()->where('user_id', $userId)->where('course_id', $course->id)->count());

        $this->postJson('/api/v1/webhooks/bosta', [
            'external_shipment_id' => $order->bostaShipment?->external_shipment_id,
            'status' => 'Delivered',
        ], ['X-Bosta-Test-Secret' => 'test-secret'])
            ->assertOk();
        $this->assertSame(1, Enrollment::query()->where('user_id', $userId)->where('course_id', $course->id)->count());
    }

    public function test_scenario_5_and_6_retry_and_concurrent_style_second_activation_idempotent(): void
    {
        Mail::fake();
        [$course, $kit] = $this->kitWithCourse();
        $orderId = $this->placePaidGuestKit($kit, 'inv56');
        $raw = $this->forceClaimRaw($orderId, 'scen56claimtokenvalue0123456789abc');

        $first = $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Race User',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk();

        $user = User::query()->where('email', 'guest@example.com')->firstOrFail();
        $this->assertSame(1, User::query()->where('email', 'guest@example.com')->count());
        $this->assertSame($user->id, (int) Order::query()->find($orderId)?->user_id);

        // Unauthenticated retry → consumed response (no second user).
        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Race User 2',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422);

        $this->assertSame(1, User::query()->where('email', 'guest@example.com')->count());

        // Authenticated retry → idempotent success.
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume')
            ->assertOk()
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.visible_in_my_orders', true);

        $this->assertSame($user->id, (int) Order::query()->find($orderId)?->user_id);
        $this->assertSame(1, GuestPurchaseClaim::query()->where('order_id', $orderId)->whereNotNull('consumed_at')->count());
        $this->assertSame($first->json('data.order_number'), Order::query()->find($orderId)?->order_number);
    }

    public function test_scenario_7_invalid_claim_no_mutation(): void
    {
        $usersBefore = User::query()->count();
        $ordersBefore = Order::query()->whereNotNull('user_id')->count();

        $this->postJson('/api/v1/guest/claims/notarealtokenvalue0123456789abcd/consume', [
            'name' => 'X',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422);

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($ordersBefore, Order::query()->whereNotNull('user_id')->count());
    }

    public function test_scenario_9_email_alone_without_claim_cannot_attach(): void
    {
        [$course, $kit] = $this->kitWithCourse();
        $user = User::factory()->create(['email' => 'guest@example.com']);
        $orderId = $this->placePaidGuestKit($kit, 'inv09');

        $this->assertNull(Order::query()->find($orderId)?->user_id);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_scenario_10_admin_session_cannot_steal_ownership(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        [$course, $kit] = $this->kitWithCourse();
        $orderId = $this->placePaidGuestKit($kit, 'inv10');
        $raw = $this->forceClaimRaw($orderId, 'scen10claimtokenvalue0123456789ab');

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Guest',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422);

        $this->assertNull(Order::query()->find($orderId)?->user_id);
        $this->assertNull(User::query()->where('email', 'guest@example.com')->value('id'));
    }

    public function test_scenario_11_mail_failure_does_not_undo_ownership(): void
    {
        Mail::fake();
        [$course, $kit] = $this->kitWithCourse();
        $orderId = $this->placePaidGuestKit($kit, 'inv11');
        $raw = $this->forceClaimRaw($orderId, 'scen11claimtokenvalue0123456789ab');

        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Mail Fail',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk();

        $user = User::query()->where('email', 'guest@example.com')->firstOrFail();
        $this->assertSame($user->id, (int) Order::query()->find($orderId)?->user_id);

        // Activation commits ownership before any mail; a subsequent mail exception must not detach.
        try {
            app(GuestPurchaseClaimService::class)->sendClaimEmail(
                Order::query()->findOrFail($orderId),
                'unusedrawtokenvalue0123456789abcdef'
            );
        } catch (\Throwable) {
            // Mail::fake absorbs sends; ownership must remain regardless.
        }

        $this->assertSame($user->id, (int) Order::query()->find($orderId)?->user_id);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonFragment([
            'order_number' => Order::query()->find($orderId)?->order_number,
        ]);
    }

    public function test_scenario_12_enrollment_failure_keeps_ownership(): void
    {
        Mail::fake();
        $courseProduct = $this->courseProduct();
        $this->withHeader('X-Cart-Session', 'inv12')
            ->postJson('/api/v1/cart/items', ['product_id' => $courseProduct->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'inv12')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
            ])->assertCreated();
        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $this->assertNotNull(Order::query()->find($orderId)?->fulfilled_at);

        $raw = $this->forceClaimRaw($orderId, 'scen12claimtokenvalue0123456789ab');

        $mock = Mockery::mock(EnrollUserService::class);
        $mock->shouldReceive('enroll')->andThrow(new \RuntimeException('enrollment down'));
        $this->app->instance(EnrollUserService::class, $mock);

        Log::spy();

        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Enroll Fail',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk()->assertJsonPath('data.visible_in_my_orders', true);

        $user = User::query()->where('email', 'guest@example.com')->firstOrFail();
        $this->assertSame($user->id, (int) Order::query()->find($orderId)?->user_id);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_scenario_15_gokuss_equivalent_register_without_claim_then_service_attach(): void
    {
        Mail::fake();
        [$course, $kit] = $this->kitWithCourse();
        $orderId = $this->placePaidGuestKit($kit, 'inv15');

        // Simulate customer registering outside claim flow.
        $user = User::factory()->create(['email' => 'guest@example.com']);
        $this->assertNull(Order::query()->find($orderId)?->user_id);

        app(GuestPurchaseClaimService::class)->attachOrderToUser(
            Order::query()->findOrFail($orderId),
            $user,
        );

        $this->assertSame($user->id, (int) Order::query()->find($orderId)?->user_id);
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertNull(Order::query()->find($orderId)?->fulfilled_at);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonFragment(['order_number' => Order::query()->find($orderId)?->order_number]);
    }

    public function test_activation_never_returns_success_when_ownership_missing(): void
    {
        Mail::fake();
        [$course, $kit] = $this->kitWithCourse();
        $orderId = $this->placePaidGuestKit($kit, 'invassert');
        $raw = $this->forceClaimRaw($orderId, 'assertclaimtokenvalue0123456789abc');

        $resp = $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Assert User',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk();

        $this->assertTrue($resp->json('data.visible_in_my_orders'));
        $userId = (int) User::query()->where('email', 'guest@example.com')->value('id');
        $this->assertSame($userId, (int) Order::query()->find($orderId)?->user_id);
    }

    /**
     * @return array{0: Course, 1: Product}
     */
    private function kitWithCourse(): array
    {
        $course = Course::query()->create([
            'slug' => 'inv-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $kit = Product::query()->create([
            'sku' => 'KIT-INV-'.uniqid(),
            'slug' => 'kit-inv-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 2790,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Microscope', 'ar' => 'ميكروسكوب'],
        ]);

        return [$course, $kit];
    }

    private function courseProduct(): Product
    {
        $course = Course::query()->create([
            'slug' => 'inv-dig-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Digital', 'ar' => 'رقمي'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);

        return Product::query()->create([
            'sku' => 'CRS-INV-'.uniqid(),
            'slug' => 'crs-inv-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Course', 'ar' => 'كورس'],
        ]);
    }

    private function placePaidGuestKit(Product $kit, string $session): int
    {
        $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
                'shipping_address' => $this->billing(),
            ])->assertCreated();
        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        return $orderId;
    }

    private function forceClaimRaw(int $orderId, string $raw): string
    {
        $order = Order::query()->findOrFail($orderId);
        app(GuestPurchaseClaimService::class)->ensureClaimForOrder($order);

        $claim = GuestPurchaseClaim::query()->where('order_id', $orderId)->whereNull('consumed_at')->firstOrFail();
        $claim->update(['token_hash' => GuestTokenHasher::hash($raw)]);

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function billing(): array
    {
        return [
            'first_name' => 'Guest',
            'last_name' => 'Buyer',
            'email' => 'guest@example.com',
            'phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => '123 Test Street Nasr',
            'district' => 'Nasr City',
            'district_name' => 'Nasr City',
            'bosta_district_id' => 'district-nasr',
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
        ];
    }
}
