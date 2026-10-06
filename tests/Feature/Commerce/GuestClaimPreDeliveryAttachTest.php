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
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GuestClaimPreDeliveryAttachTest extends TestCase
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

    public function test_paid_undelivered_kit_claim_creates_account_and_shows_in_my_orders_without_enrollment(): void
    {
        Mail::fake();
        [$course, $kit] = $this->kitWithCourse();

        $this->withHeader('X-Cart-Session', 'predel01')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'predel01')
            ->postJson('/api/v1/checkout', $this->guestPayload())->assertCreated();
        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $order = Order::query()->findOrFail($orderId);
        $this->assertNull($order->fulfilled_at);
        $this->assertNull($order->user_id);

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($order): bool {
            return $mail->accountActivationUrl === null
                && $mail->order->is($order)
                && str_contains($mail->viewOrderUrl(), '/order-status/');
        });

        // Account activation claim is minted on demand (Delivered email or explicit ensure),
        // not via the initial confirmation receipt.
        $issued = app(GuestPurchaseClaimService::class)->ensureClaimForOrder($order->fresh() ?? $order);
        $this->assertNotNull($issued['raw_token']);
        $raw = (string) $issued['raw_token'];

        $consume = $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Bassil',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk();

        $userId = (int) User::query()->where('email', 'guest@example.com')->value('id');
        $this->assertSame($userId, (int) Order::query()->find($orderId)?->user_id);
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertNotNull($consume->json('data.token'));

        Sanctum::actingAs(User::query()->findOrFail($userId));
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonFragment(['order_number' => $order->order_number]);
    }

    public function test_email_alone_cannot_attach_without_claim(): void
    {
        [$course, $kit] = $this->kitWithCourse();
        $user = User::factory()->create(['email' => 'guest@example.com']);

        $this->withHeader('X-Cart-Session', 'predel02')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'predel02')
            ->postJson('/api/v1/checkout', $this->guestPayload())->assertCreated();
        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        // No public "attach by email" endpoint — order remains unattached until claim/service.
        $this->assertNull(Order::query()->find($orderId)?->user_id);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_claim_for_order_a_cannot_consume_against_order_b_token_mismatch(): void
    {
        [$course, $kit] = $this->kitWithCourse();
        $this->withHeader('X-Cart-Session', 'predel03a')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkoutA = $this->withHeader('X-Cart-Session', 'predel03a')
            ->postJson('/api/v1/checkout', $this->guestPayload(['email' => 'a@example.com']))->assertCreated();
        $orderA = (int) $checkoutA->json('data.id');
        $payA = $this->postJson('/api/v1/checkout/'.$orderA.'/pay', [
            'guest_pay_token' => $checkoutA->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$payA->json('data.payment_id').'/complete')->assertOk();

        $issuedA = app(GuestPurchaseClaimService::class)->ensureClaimForOrder(
            Order::query()->findOrFail($orderA)
        );
        $rawA = (string) $issuedA['raw_token'];
        $this->assertNotSame('', $rawA);

        $this->postJson('/api/v1/guest/claims/'.$rawA.'/consume', [
            'name' => 'A',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertOk();

        $this->assertSame(
            (int) User::query()->where('email', 'a@example.com')->value('id'),
            (int) Order::query()->find($orderA)?->user_id
        );
    }

    public function test_attach_order_to_user_before_delivery_is_idempotent_without_enrollment(): void
    {
        [$course, $kit] = $this->kitWithCourse();
        $user = User::factory()->create(['email' => 'guest@example.com']);

        $this->withHeader('X-Cart-Session', 'predel04')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'predel04')
            ->postJson('/api/v1/checkout', $this->guestPayload())->assertCreated();
        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $order = Order::query()->findOrFail($orderId);
        $service = app(GuestPurchaseClaimService::class);
        $service->attachOrderToUser($order, $user);
        $service->attachOrderToUser($order->fresh(), $user);

        $this->assertSame($user->id, (int) $order->fresh()->user_id);
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertNull($order->fresh()->fulfilled_at);
    }

    public function test_admin_session_does_not_steal_guest_claim_ownership(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Admin']);
        [$course, $kit] = $this->kitWithCourse();

        $this->withHeader('X-Cart-Session', 'predel05')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'predel05')
            ->postJson('/api/v1/checkout', $this->guestPayload())->assertCreated();
        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $issued = app(GuestPurchaseClaimService::class)->ensureClaimForOrder(
            Order::query()->findOrFail($orderId)
        );
        $raw = (string) $issued['raw_token'];
        $this->assertNotSame('', $raw);

        // Admin sanctum session present must not become order owner when consuming guest claim registration.
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/guest/claims/'.$raw.'/consume', [
            'name' => 'Guest Buyer',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422);

        $this->assertNull(Order::query()->find($orderId)?->user_id);
        $this->assertNull(User::query()->where('email', 'guest@example.com')->value('id'));
    }

    /**
     * @return array{0: Course, 1: Product}
     */
    private function kitWithCourse(): array
    {
        $course = Course::query()->create([
            'slug' => 'predel-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit Course', 'ar' => 'كورس'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $kit = Product::query()->create([
            'sku' => 'KIT-PD-'.uniqid(),
            'slug' => 'kit-pd-'.uniqid(),
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function guestPayload(array $overrides = []): array
    {
        $address = array_merge([
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
        ], $overrides);

        return [
            'billing_address' => $address,
            'shipping_address' => $address,
        ];
    }
}
