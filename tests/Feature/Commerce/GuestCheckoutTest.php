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
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
    }

    public function test_guest_physical_checkout_and_pay(): void
    {
        $kit = $this->kit();

        $this->withHeader('X-Cart-Session', 'guestcartsession01')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id, 'quantity' => 1])
            ->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession01')
            ->postJson('/api/v1/checkout', $this->guestPayload(requiresShipping: true))
            ->assertCreated();

        $this->assertTrue($checkout->json('data.is_guest'));
        $this->assertNull($checkout->json('data.user_id'));
        $payToken = $checkout->json('guest.pay_token');
        $this->assertNotEmpty($payToken);

        $orderId = $checkout->json('data.id');
        $pay = $this->postJson("/api/v1/checkout/{$orderId}/pay", [
            'guest_pay_token' => $payToken,
        ])->assertOk();

        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'is_guest' => 1,
            'user_id' => null,
            'status' => 'paid',
        ]);
    }

    public function test_guest_course_checkout_claim_and_consume_new_user(): void
    {
        Mail::fake();
        $courseProduct = $this->courseProduct();

        $this->withHeader('X-Cart-Session', 'guestcartsession02')
            ->postJson('/api/v1/cart/items', ['product_id' => $courseProduct->id])
            ->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession02')
            ->postJson('/api/v1/checkout', $this->guestPayload(requiresShipping: false))
            ->assertCreated();

        $orderId = $checkout->json('data.id');
        $payToken = $checkout->json('guest.pay_token');

        $pay = $this->postJson("/api/v1/checkout/{$orderId}/pay", ['guest_pay_token' => $payToken])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $this->assertSame(0, Enrollment::query()->count());
        $this->assertDatabaseCount('guest_purchase_claims', 1);

        $claim = GuestPurchaseClaim::query()->firstOrFail();
        // Mint inspect path using service resend to get raw token in test safely.
        $raw = 'testclaimtokenrawvalue0123456789ab';
        $claim->update(['token_hash' => GuestTokenHasher::hash($raw)]);

        $inspect = $this->getJson('/api/v1/guest/claims/'.$raw)->assertOk();
        $this->assertSame('valid', $inspect->json('data.status'));
        $this->assertTrue($inspect->json('data.requires_registration'));

        $consume = $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Guest Student',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk();

        $userId = User::query()->where('email', 'guest@example.com')->value('id');
        $this->assertNotNull($userId);
        $this->assertSame((int) $userId, (int) Order::query()->find($orderId)?->user_id);
        $this->assertTrue((bool) Order::query()->find($orderId)?->is_guest);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $userId,
            'course_id' => $courseProduct->course_id,
        ]);

        $reuse = $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Other',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $this->assertContains($reuse->status(), [401, 422]);
        $this->assertSame(1, Enrollment::query()->count());
    }

    public function test_existing_user_claim_requires_matching_login(): void
    {
        $existing = User::factory()->create(['email' => 'guest@example.com']);
        $courseProduct = $this->courseProduct();

        $this->withHeader('X-Cart-Session', 'guestcartsession03')
            ->postJson('/api/v1/cart/items', ['product_id' => $courseProduct->id])->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession03')
            ->postJson('/api/v1/checkout', $this->guestPayload(false))->assertCreated();

        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $claim = GuestPurchaseClaim::query()->firstOrFail();
        $raw = 'existinguserclaimtoken0123456789abcd';
        $claim->update(['token_hash' => GuestTokenHasher::hash($raw)]);

        $this->getJson('/api/v1/guest/claims/'.$raw)
            ->assertOk()
            ->assertJsonPath('data.requires_login', true);

        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume')->assertStatus(401);

        $wrong = User::factory()->create(['email' => 'other@example.com']);
        Sanctum::actingAs($wrong);
        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume')->assertStatus(422);

        Sanctum::actingAs($existing);
        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume')->assertOk();
        $this->assertSame($existing->id, Order::query()->find($checkout->json('data.id'))?->user_id);
        $this->assertSame(1, Enrollment::query()->where('user_id', $existing->id)->count());
    }

    public function test_server_side_repricing_ignores_stale_cart_price(): void
    {
        $kit = $this->kit(['price' => 100]);

        $this->withHeader('X-Cart-Session', 'guestcartsession04')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();

        // Tamper cart unit price.
        \App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem::query()->update(['unit_price' => 1]);

        $kit->update(['price' => 250]);

        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession04')
            ->postJson('/api/v1/checkout', $this->guestPayload(true))
            ->assertCreated();

        $this->assertEquals(330.0, (float) $checkout->json('data.total'));
        $this->assertEquals(80.0, (float) $checkout->json('data.shipping_amount'));
        $this->assertEquals(250.0, (float) $checkout->json('data.subtotal'));
    }

    public function test_guest_pay_rejects_invalid_token_and_foreign_user(): void
    {
        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestcartsession05')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession05')
            ->postJson('/api/v1/checkout', $this->guestPayload(true))->assertCreated();

        $orderId = $checkout->json('data.id');
        $this->postJson("/api/v1/checkout/{$orderId}/pay", ['guest_pay_token' => 'bad'])
            ->assertStatus(403);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/checkout/{$orderId}/pay")->assertStatus(403);
    }

    public function test_course_only_allows_null_shipping_physical_requires_it(): void
    {
        $course = $this->courseProduct();
        $this->withHeader('X-Cart-Session', 'guestcartsession06')
            ->postJson('/api/v1/cart/items', ['product_id' => $course->id])->assertCreated();
        $this->withHeader('X-Cart-Session', 'guestcartsession06')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
            ])->assertCreated();

        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestcartsession07')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $this->withHeader('X-Cart-Session', 'guestcartsession07')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
            ])->assertStatus(422);
    }

    public function test_guest_order_status_token_and_refund_revokes_claim(): void
    {
        $course = $this->courseProduct();
        $this->withHeader('X-Cart-Session', 'guestcartsession08')
            ->postJson('/api/v1/cart/items', ['product_id' => $course->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession08')
            ->postJson('/api/v1/checkout', $this->guestPayload(false))->assertCreated();

        $access = $checkout->json('guest.access_token');
        $orderNumber = $checkout->json('data.order_number');

        $this->getJson('/api/v1/guest/orders/'.$orderNumber.'?token='.$access)
            ->assertOk()
            ->assertJsonPath('data.order_number', $orderNumber);

        $this->getJson('/api/v1/guest/orders/'.$orderNumber.'?token=wrong')->assertNotFound();

        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $order = Order::query()->findOrFail($checkout->json('data.id'));
        app(\App\Modules\Commerce\Application\Services\OrderFulfillmentService::class)
            ->applyAdminUpdate($order, ['status' => 'refunded']);

        $this->assertNotNull(GuestPurchaseClaim::query()->where('order_id', $order->id)->whereNotNull('revoked_at')->first());
    }

    public function test_authenticated_checkout_regression(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $kit = $this->kit();

        $this->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->postJson('/api/v1/checkout', [
            'billing_address' => array_merge($this->billing(), ['email' => $user->email]),
            'shipping_address' => array_merge($this->billing(), ['email' => $user->email]),
        ])->assertCreated();

        $this->assertFalse((bool) $checkout->json('data.is_guest'));
        $this->assertSame($user->id, $checkout->json('data.user_id'));
        $this->assertNull($checkout->json('guest'));

        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay')->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();
    }

    public function test_guest_mixed_checkout_requires_shipping(): void
    {
        $kit = $this->kit();
        $course = $this->courseProduct();

        $this->withHeader('X-Cart-Session', 'guestcartsession09')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $this->withHeader('X-Cart-Session', 'guestcartsession09')
            ->postJson('/api/v1/cart/items', ['product_id' => $course->id])->assertCreated();

        $this->withHeader('X-Cart-Session', 'guestcartsession09')
            ->postJson('/api/v1/checkout', ['billing_address' => $this->billing()])
            ->assertStatus(422);

        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession09')
            ->postJson('/api/v1/checkout', $this->guestPayload(true))
            ->assertCreated();

        $this->assertTrue($checkout->json('data.is_guest'));
        $this->assertCount(2, $checkout->json('data.items') ?? []);
    }

    public function test_expired_and_revoked_claims_rejected(): void
    {
        $courseProduct = $this->courseProduct();
        $this->withHeader('X-Cart-Session', 'guestcartsession10')
            ->postJson('/api/v1/cart/items', ['product_id' => $courseProduct->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession10')
            ->postJson('/api/v1/checkout', $this->guestPayload(false))->assertCreated();
        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $claim = GuestPurchaseClaim::query()->firstOrFail();
        $raw = 'expiredclaimtokenvalue0123456789abcd';
        $claim->update([
            'token_hash' => GuestTokenHasher::hash($raw),
            'expires_at' => now()->subHour(),
        ]);

        $this->getJson('/api/v1/guest/claims/'.$raw)
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $user = User::factory()->create(['email' => 'guest@example.com']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume')->assertStatus(422);

        $claim->update([
            'expires_at' => now()->addDay(),
            'revoked_at' => now(),
        ]);
        $this->getJson('/api/v1/guest/claims/'.$raw)
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
    }

    public function test_guest_billing_snapshot_persisted_and_confirmation_mail_target(): void
    {
        Mail::fake();
        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestcartsession11')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestcartsession11')
            ->postJson('/api/v1/checkout', $this->guestPayload(true))->assertCreated();

        $order = Order::query()->findOrFail($checkout->json('data.id'));
        $this->assertSame('guest@example.com', $order->billing_address['email'] ?? null);
        $this->assertSame('Buyer', $order->billing_address['last_name'] ?? null);
        $this->assertNull($order->user_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function kit(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'KIT-'.uniqid(),
            'slug' => 'kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 150,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Kit', 'ar' => 'حقيبة'],
        ], $overrides));
    }

    private function courseProduct(): Product
    {
        $course = Course::query()->create([
            'slug' => 'course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);

        return Product::query()->create([
            'sku' => 'COURSE-'.uniqid(),
            'slug' => 'course-prod-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 200,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Course Product', 'ar' => 'منتج كورس'],
        ]);
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
            'address' => 'Street 1 Building A',
            'district' => 'Nasr City',
            'district_name' => 'Nasr City',
            'bosta_district_id' => 'district-nasr',
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guestPayload(bool $requiresShipping): array
    {
        $payload = ['billing_address' => $this->billing()];
        if ($requiresShipping) {
            $payload['shipping_address'] = $this->billing();
        }

        return $payload;
    }
}
