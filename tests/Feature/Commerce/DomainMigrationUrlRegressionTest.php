<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\GuestPurchaseClaimService;
use App\Modules\Commerce\Application\Services\OrderDeliveredMailService;
use App\Modules\Commerce\Application\Services\OrderFulfillmentService;
use App\Modules\Commerce\Application\Support\MicroscopePurchaseOptions;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Commerce\Mail\OrderDeliveredMail;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Domain cutover regressions: canonical apex URLs, no WordPress signup leaks,
 * microscope book_language persistence through cart → order.
 */
final class DomainMigrationUrlRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'https://sciencestreetlab.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::FRONTEND,
            'sciencestreet.frontend_url' => self::FRONTEND,
            'sciencestreet.default_locale' => 'ar',
            'bosta.enabled' => false,
        ]);
        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
    }

    public function test_guest_activation_url_uses_configured_frontend_claim_route(): void
    {
        Mail::fake();
        $order = $this->guestMicroscopeOrder();

        app(OrderFulfillmentService::class)->fulfillFromAdminDelivery($order->fresh());
        app(OrderDeliveredMailService::class)->notifyIfNeeded($order->fresh());

        Mail::assertSent(OrderDeliveredMail::class, function (OrderDeliveredMail $mail): bool {
            $url = (string) $mail->activationUrl;
            $this->assertStringStartsWith(self::FRONTEND.'/claim/', $url);
            $this->assertStringNotContainsString('wp-signup.php', $url);
            $this->assertStringNotContainsString('wp-login.php', $url);
            $this->assertStringNotContainsString('app.sciencestreetlab.com', $url);

            return true;
        });
    }

    public function test_password_reset_url_uses_configured_frontend_react_route(): void
    {
        $user = User::factory()->create(['email' => 'reset-domain@example.com']);
        $notification = new ResetPasswordNotification('test-reset-token-value');
        $method = new \ReflectionMethod(ResetPasswordNotification::class, 'resetUrl');
        $method->setAccessible(true);
        $url = (string) $method->invoke($notification, $user);

        $this->assertStringStartsWith(self::FRONTEND.'/my-account/reset-password?', $url);
        $this->assertStringContainsString('email='.rawurlencode($user->email), $url);
        $this->assertStringNotContainsString('wp-signup.php', $url);
        $this->assertStringNotContainsString('app.sciencestreetlab.com', $url);
    }

    public function test_order_confirmation_view_order_uses_frontend_not_wordpress(): void
    {
        Mail::fake();
        $order = $this->guestMicroscopeOrder();

        Mail::to('guest@example.com')->send(new OrderConfirmationMail($order->fresh(['items', 'user'])));

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($order): bool {
            $html = $mail->render();
            $this->assertStringNotContainsString('wp-signup.php', $html);
            $this->assertStringNotContainsString('wp-login.php', $html);
            $this->assertStringContainsString(self::FRONTEND, $html);

            return str_contains($html, $order->order_number) || str_contains($html, '/order-status/');
        });
    }

    public function test_microscope_arabic_persists_on_cart_api_and_order_snapshot(): void
    {
        $product = $this->microscopeKit();

        $this->withHeader('X-Cart-Session', 'dom-ar-1')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
                'book_language' => 'ar',
            ])
            ->assertCreated();

        $cart = $this->withHeader('X-Cart-Session', 'dom-ar-1')
            ->getJson('/api/v1/cart')
            ->assertOk();

        $this->assertSame('ar', $cart->json('data.items.0.metadata.book_language'));
        $this->assertSame(
            MicroscopePurchaseOptions::optionsKey(['book_language' => 'ar']),
            $cart->json('data.items.0.options_key'),
        );

        $checkout = $this->withHeader('X-Cart-Session', 'dom-ar-1')
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->address(),
                'shipping_address' => $this->address(),
                'payment_method' => 'cash_on_delivery',
            ])
            ->assertCreated();

        $order = Order::query()->with('items')->findOrFail((int) $checkout->json('data.id'));
        $this->assertSame('ar', $order->items->first()?->metadata['book_language'] ?? null);
    }

    public function test_microscope_english_persists_and_quantity_preserves_language(): void
    {
        $product = $this->microscopeKit();

        $add = $this->withHeader('X-Cart-Session', 'dom-en-1')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
                'book_language' => 'en',
            ])
            ->assertCreated();

        $itemId = (int) $add->json('data.id');
        $this->withHeader('X-Cart-Session', 'dom-en-1')
            ->putJson('/api/v1/cart/items/'.$itemId, ['quantity' => 3])
            ->assertOk();

        $cart = $this->withHeader('X-Cart-Session', 'dom-en-1')->getJson('/api/v1/cart')->assertOk();
        $this->assertSame(3, (int) $cart->json('data.items.0.quantity'));
        $this->assertSame('en', $cart->json('data.items.0.metadata.book_language'));
    }

    public function test_invalid_legacy_microscope_line_upgraded_when_language_selected(): void
    {
        $product = $this->microscopeKit();
        $cart = Cart::query()->create(['session_id' => 'dom-legacy-1', 'expires_at' => now()->addDay()]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
            'metadata' => null,
            'options_key' => '',
        ]);

        $this->withHeader('X-Cart-Session', 'dom-legacy-1')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
                'book_language' => 'ar',
            ])
            ->assertCreated();

        $items = $this->withHeader('X-Cart-Session', 'dom-legacy-1')
            ->getJson('/api/v1/cart')
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('ar', $items[0]['metadata']['book_language'] ?? null);
    }

    public function test_guest_claim_activate_does_not_depend_on_wordpress_urls(): void
    {
        $order = $this->guestMicroscopeOrder();
        app(OrderFulfillmentService::class)->fulfillFromAdminDelivery($order->fresh());
        $issued = app(GuestPurchaseClaimService::class)->issueRawTokenForEmail($order->fresh());

        $this->assertArrayHasKey('raw_token', $issued);
        $this->assertDoesNotMatchRegularExpression('/wp-(signup|login)\.php/', $issued['raw_token']);
    }

    private function microscopeKit(): Product
    {
        $course = Course::query()->create([
            'slug' => 'micro-dom-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Microscope', 'ar' => 'ميكروسكوب'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);

        return Product::query()->create([
            'sku' => MicroscopePurchaseOptions::SKU,
            'slug' => MicroscopePurchaseOptions::SLUG,
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 2790,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Science Street Microscope', 'ar' => 'ميكروسكوب شارع العلوم'],
            'free_shipping' => true,
        ]);
    }

    private function guestMicroscopeOrder(): Order
    {
        $product = $this->microscopeKit();
        $session = 'dom-guest-'.uniqid();
        $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
                'book_language' => 'ar',
            ])->assertCreated();

        $checkout = $this->withHeader('X-Cart-Session', $session)
            ->postJson('/api/v1/checkout', [
                'billing_address' => $this->address(),
                'shipping_address' => $this->address(),
                'payment_method' => 'cash_on_delivery',
            ])->assertCreated();

        return Order::query()->with('items')->findOrFail((int) $checkout->json('data.id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Guest',
            'last_name' => 'Buyer',
            'email' => 'domain-guest@example.com',
            'phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => '123 Test Street',
            'district' => 'Nasr City',
            'district_name' => 'Nasr City',
            'bosta_district_id' => 'district-nasr',
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
        ];
    }
}
