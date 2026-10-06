<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\GuestOrderCapabilityService;
use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Commerce\Infrastructure\Persistence\Models\GuestOrderCapability;
use App\Modules\Commerce\Infrastructure\Persistence\Models\GuestPurchaseClaim;
use App\Modules\Commerce\Mail\GuestCourseClaimMail;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Commerce\Mail\OrderDeliveredMail;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GuestCheckoutMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
    }

    public function test_guest_confirmation_goes_to_billing_email_with_secure_status_url(): void
    {
        Mail::fake();
        config(['sciencestreet.frontend_url' => 'https://app.example.test', 'sciencestreet.default_locale' => 'ar']);

        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestmailsession01')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', 'guestmailsession01')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
                'shipping_address' => $this->billing(),
            ])->assertCreated();

        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($checkout): bool {
            $orderNumber = (string) $checkout->json('data.order_number');
            $html = $mail->render();
            $url = $mail->viewOrderUrl();

            $this->assertTrue($mail->hasTo('guest@example.com'));
            $this->assertStringContainsString('تم استلام طلبك', $mail->envelope()->subject);
            $this->assertStringContainsString('/order-status/'.$orderNumber.'?token=', $url);
            $this->assertStringContainsString($url, $html);

            $token = (string) parse_url($url, PHP_URL_QUERY);
            parse_str($token, $query);
            $raw = (string) ($query['token'] ?? '');
            $this->assertNotSame('', $raw);
            $this->assertSame(64, strlen(GuestTokenHasher::hash($raw)));
            $this->assertStringNotContainsString(GuestTokenHasher::hash($raw), $url);

            return true;
        });
    }

    public function test_emailed_status_token_resolves_and_wrong_token_rejected(): void
    {
        Mail::fake();
        config(['sciencestreet.frontend_url' => 'https://app.example.test']);

        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestmailsession02')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestmailsession02')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
                'shipping_address' => $this->billing(),
            ])->assertCreated();

        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        $raw = null;
        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use (&$raw): bool {
            parse_str((string) parse_url($mail->viewOrderUrl(), PHP_URL_QUERY), $query);
            $raw = (string) ($query['token'] ?? '');

            return $raw !== '';
        });

        $orderNumber = (string) $checkout->json('data.order_number');
        $this->getJson('/api/v1/guest/orders/'.$orderNumber.'?token='.$raw)
            ->assertOk()
            ->assertJsonPath('data.order_number', $orderNumber);

        $this->getJson('/api/v1/guest/orders/'.$orderNumber.'?token=wrongtokenvalue')
            ->assertNotFound();
    }

    public function test_guest_claim_mail_subject_localized_and_ttls_unchanged(): void
    {
        Mail::fake();
        config(['sciencestreet.default_locale' => 'ar']);

        $course = $this->courseProduct();
        $this->withHeader('X-Cart-Session', 'guestmailsession03')
            ->postJson('/api/v1/cart/items', ['product_id' => $course->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestmailsession03')
            ->postJson('/api/v1/checkout', ['billing_address' => $this->billing()])->assertCreated();

        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay', [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        Mail::assertSent(OrderDeliveredMail::class, function (OrderDeliveredMail $mail): bool {
            return str_contains($mail->envelope()->subject, 'تم توصيل طلبك');
        });

        $claim = GuestPurchaseClaim::query()->firstOrFail();
        $this->assertTrue($claim->expires_at?->greaterThan(now()->addHours(71)));
        $this->assertTrue($claim->expires_at?->lessThanOrEqualTo(now()->addHours(72)->addMinute()));

        $status = GuestOrderCapability::query()
            ->where('order_id', $checkout->json('data.id'))
            ->where('type', 'status')
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertTrue($status->expires_at?->greaterThan(now()->addDays(29)));
        $this->assertTrue($status->expires_at?->lessThanOrEqualTo(now()->addDays(30)->addMinute()));

        $payCap = GuestOrderCapability::query()
            ->where('order_id', $checkout->json('data.id'))
            ->where('type', 'pay')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertTrue($payCap->expires_at?->lessThanOrEqualTo(now()->addHours(24)->addMinute()));
    }

    public function test_duplicate_confirmation_does_not_create_unbounded_active_status_capabilities(): void
    {
        Mail::fake();

        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestmailsession04')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestmailsession04')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
                'shipping_address' => $this->billing(),
            ])->assertCreated();

        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson("/api/v1/checkout/{$orderId}/pay", [
            'guest_pay_token' => $checkout->json('guest.pay_token'),
        ])->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        // Simulate listener retry / duplicate fulfillment after already sent.
        $order = \App\Modules\Commerce\Infrastructure\Persistence\Models\Order::query()->findOrFail($orderId);
        event(new \App\Modules\Commerce\Domain\Events\OrderFulfilled($order));

        $activeStatus = GuestOrderCapability::query()
            ->where('order_id', $orderId)
            ->where('type', 'status')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        $this->assertSame(1, $activeStatus);
        Mail::assertSent(OrderConfirmationMail::class, 1);
    }

    public function test_authenticated_confirmation_mail_regression(): void
    {
        Mail::fake();
        config(['sciencestreet.frontend_url' => 'https://app.example.test', 'sciencestreet.default_locale' => 'en']);

        $user = User::factory()->create(['locale' => 'en', 'email' => 'auth-buyer@example.com']);
        Sanctum::actingAs($user);
        $kit = $this->kit();
        $this->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->postJson('/api/v1/checkout', [
            'billing_address' => array_merge($this->billing(), ['email' => $user->email]),
            'shipping_address' => array_merge($this->billing(), ['email' => $user->email]),
        ])->assertCreated();

        $pay = $this->postJson('/api/v1/checkout/'.$checkout->json('data.id').'/pay')->assertOk();
        $this->postJson('/api/v1/payments/mock/'.$pay->json('data.payment_id').'/complete')->assertOk();

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($user, $checkout): bool {
            $url = $mail->viewOrderUrl();

            return $mail->hasTo($user->email)
                && str_contains($mail->envelope()->subject, 'We received your order')
                && str_contains($url, '/account/orders/'.$checkout->json('data.order_number'))
                && ! str_contains($url, 'token=');
        });
    }

    public function test_guest_cod_sends_confirmation_to_billing_email(): void
    {
        Mail::fake();
        config([
            'sciencestreet.frontend_url' => 'https://app.example.test',
            'sciencestreet.default_locale' => 'ar',
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
        ]);

        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestcodmail01')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', 'guestcodmail01')
            ->postJson('/api/v1/checkout', [
                'payment_method' => 'cod',
                'billing_address' => $this->billing(['email' => 'guest-cod@example.test']),
                'shipping_address' => $this->billing(['email' => 'guest-cod@example.test']),
            ])->assertCreated();

        $order = \App\Modules\Commerce\Infrastructure\Persistence\Models\Order::query()
            ->findOrFail((int) $checkout->json('data.id'));

        $this->assertTrue((bool) $order->is_guest);
        $this->assertNull($order->user_id);
        $this->assertNotNull($order->confirmation_email_sent_at);

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail): bool {
            return $mail->hasTo('guest-cod@example.test')
                && str_contains($mail->envelope()->subject, 'تم استلام طلبك');
        });
        Mail::assertSent(OrderConfirmationMail::class, 1);
    }

    public function test_admin_web_session_does_not_own_guest_checkout_or_steal_confirmation_recipient(): void
    {
        Mail::fake();
        config([
            'sciencestreet.frontend_url' => 'https://app.example.test',
            'sciencestreet.default_locale' => 'ar',
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
        ]);

        $admin = User::factory()->create(['email' => 'admin-session@sciencestreetlab.com']);
        $this->actingAs($admin, 'web');

        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestadminmail01')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', 'guestadminmail01')
            ->postJson('/api/v1/checkout', [
                'payment_method' => 'cod',
                'billing_address' => $this->billing(['email' => 'guest@example.test']),
                'shipping_address' => $this->billing(['email' => 'guest@example.test']),
            ])->assertCreated();

        $order = \App\Modules\Commerce\Infrastructure\Persistence\Models\Order::query()
            ->findOrFail((int) $checkout->json('data.id'));

        $this->assertTrue((bool) $order->is_guest);
        $this->assertNull($order->user_id);
        $this->assertNotSame($admin->id, $order->user_id);

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($admin): bool {
            return $mail->hasTo('guest@example.test')
                && ! $mail->hasTo($admin->email);
        });
    }

    public function test_failed_online_payment_does_not_send_confirmation(): void
    {
        Mail::fake();
        config(['commerce.payment_gateway' => 'mock']);

        $kit = $this->kit();
        $this->withHeader('X-Cart-Session', 'guestfailmail01')
            ->postJson('/api/v1/cart/items', ['product_id' => $kit->id])->assertCreated();
        $checkout = $this->withHeader('X-Cart-Session', 'guestfailmail01')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->billing(),
                'shipping_address' => $this->billing(),
            ])->assertCreated();

        $order = \App\Modules\Commerce\Infrastructure\Persistence\Models\Order::query()
            ->findOrFail((int) $checkout->json('data.id'));
        $this->assertNull($order->confirmation_email_sent_at);
        Mail::assertNothingSent();
    }

    public function test_english_guest_confirmation_subject_when_locale_en(): void
    {
        config(['sciencestreet.default_locale' => 'en', 'sciencestreet.frontend_url' => 'https://app.example.test']);

        $order = \App\Modules\Commerce\Infrastructure\Persistence\Models\Order::query()->create([
            'user_id' => null,
            'is_guest' => true,
            'status' => 'paid',
            'subtotal' => 10,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 10,
            'currency' => 'EGP',
            'billing_address' => $this->billing(),
            'shipping_address' => $this->billing(),
        ]);
        $order->forceFill(['order_number' => 'SS-GUEST1'])->save();

        $mail = new OrderConfirmationMail(
            order: $order->fresh() ?? $order,
            enrollmentQrs: [],
            rawStatusToken: 'raw-status-token-for-url-only',
            mailLocale: 'en',
        );

        $this->assertStringContainsString('We received your order', $mail->envelope()->subject);
        $this->assertStringContainsString('token=raw-status-token-for-url-only', $mail->viewOrderUrl());
        $this->assertStringNotContainsString(GuestTokenHasher::hash('raw-status-token-for-url-only'), $mail->viewOrderUrl());
    }

    private function kit(): Product
    {
        return Product::query()->create([
            'sku' => 'KIT-'.uniqid(),
            'slug' => 'kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 150,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Kit', 'ar' => 'حقيبة'],
        ]);
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function billing(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }
}
