<?php

declare(strict_types=1);

namespace Tests\Feature\SocialAttribution;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Listeners\CreateBostaShipmentOnOrderPaid;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Services\OrderFulfillmentService;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\SocialAttribution\Application\Listeners\RecordPurchaseConversionOnOrderPaid;
use App\Modules\SocialAttribution\Application\Services\DestinationAllowlist;
use App\Modules\SocialAttribution\Application\Services\SnapshotOrderAttribution;
use App\Modules\SocialAttribution\Application\Support\AttributionCookie;
use App\Modules\SocialAttribution\Domain\Data\AttributionContext;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\ChannelKind;
use App\Modules\SocialAttribution\Domain\Enums\ContentType;
use App\Modules\SocialAttribution\Domain\Enums\ConversionEventName;
use App\Modules\SocialAttribution\Domain\Enums\DestinationType;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionConversion;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\AttributionSession;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\OrderAttribution;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialCampaign;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialContent;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLink;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLinkClick;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class SocialAttributionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private string $frontend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->frontend = 'https://store.test';
        config([
            'sciencestreet.frontend_url' => $this->frontend,
            'social_attribution.window_days' => 30,
            'social_attribution.hash_pepper' => 'test-pepper',
            'social_attribution.cookie.secure' => false,
        ]);
    }

    public function test_go_valid_link_redirects_internally(): void
    {
        $link = $this->makeLink(code: 'ig-micro', path: '/microscope-landing-page');

        $response = $this->get('/go/ig-micro');

        $response->assertRedirect($this->frontend.'/microscope-landing-page?utm_source=instagram&utm_medium=social&utm_campaign=micro');
        $response->assertHeader('Cache-Control');
        $this->assertNotNull($response->headers->getCookies());
        $this->assertDatabaseCount('tracking_link_clicks', 1);
        $this->assertDatabaseHas('tracking_links', ['id' => $link->id]);
    }

    public function test_absolute_external_destination_rejected_by_allowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DestinationAllowlist::class)->assertSafePath('https://evil.example/phish');
    }

    public function test_protocol_relative_destination_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DestinationAllowlist::class)->assertSafePath('//evil.example/x');
    }

    public function test_encoded_redirect_attack_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DestinationAllowlist::class)->assertSafePath('/%2f%2fevil.example');
    }

    public function test_disabled_link_rejected(): void
    {
        $this->makeLink(code: 'off-link', path: '/shop', enabled: false);
        $this->get('/go/off-link')->assertNotFound();
    }

    public function test_expired_link_rejected(): void
    {
        $this->makeLink(code: 'old-link', path: '/shop', expiresAt: now()->subMinute());
        $this->get('/go/old-link')->assertNotFound();
    }

    public function test_first_and_last_touch_window_and_bot(): void
    {
        $cookie = app(AttributionCookie::class)->name();
        $a = $this->makeLink(code: 'first-ig', path: '/shop/kit-a', platform: AttributionPlatform::Instagram);
        $b = $this->makeLink(code: 'last-yt', path: '/shop/kit-b', platform: AttributionPlatform::YouTube);

        $first = $this->get('/go/first-ig');
        $first->assertRedirect();
        $visitor = $this->cookieValue($first, $cookie);
        $this->assertNotNull($visitor);

        $session = AttributionSession::query()->where('visitor_key', $visitor)->firstOrFail();
        $this->assertSame($a->id, $session->first_tracking_link_id);
        $this->assertSame($a->id, $session->last_tracking_link_id);

        $this->withUnencryptedCookie($cookie, $visitor)->get('/go/last-yt')->assertRedirect();
        $session->refresh();
        $this->assertSame($a->id, $session->first_tracking_link_id);
        $this->assertSame($b->id, $session->last_tracking_link_id);
        $this->assertSame('youtube', $session->last_platform);

        // Bot must not overwrite human attribution.
        $this->withUnencryptedCookie($cookie, $visitor)
            ->withHeader('User-Agent', 'facebookexternalhit/1.1')
            ->get('/go/first-ig')
            ->assertRedirect();
        $session->refresh();
        $this->assertSame($b->id, $session->last_tracking_link_id);
        $this->assertTrue(TrackingLinkClick::query()->where('is_bot', true)->exists());

        // Clear crawler UA so the next human hit is not treated as a bot.
        $this->withHeader('User-Agent', 'Mozilla/5.0 ScienceStreetTest');

        // Window expiry clears and allows new first touch.
        $session->update([
            'window_ends_at' => now()->subDay(),
            'first_touch' => ['stale' => true],
            'last_touch' => ['stale' => true],
            'first_touched_at' => now()->subDays(40),
            'last_touched_at' => now()->subDays(40),
            'first_tracking_link_id' => $a->id,
            'last_tracking_link_id' => $b->id,
        ]);
        $fresh = $this->makeLink(code: 'fresh-fb', path: '/courses/demo', platform: AttributionPlatform::Facebook);
        $this->withUnencryptedCookie($cookie, $visitor)->get('/go/fresh-fb')->assertRedirect();
        $session->refresh();
        $this->assertSame($fresh->id, $session->first_tracking_link_id);
        $this->assertSame($fresh->id, $session->last_tracking_link_id);
    }

    public function test_unique_human_click_race_safe_and_utm_fbclid(): void
    {
        $cookie = app(AttributionCookie::class)->name();
        $this->makeLink(code: 'utm-link', path: '/shop');

        $r1 = $this->get('/go/utm-link?utm_source=override&fbclid=abc123');
        $visitor = $this->cookieValue($r1, $cookie);
        $this->assertNotNull($visitor);

        $this->withUnencryptedCookie($cookie, $visitor)->get('/go/utm-link?fbclid=abc123')->assertRedirect();

        $clicks = TrackingLinkClick::query()->where('visitor_key', $visitor)->orderBy('id')->get();
        $this->assertCount(2, $clicks);
        $this->assertTrue($clicks[0]->is_unique_human);
        $this->assertFalse($clicks[1]->is_unique_human);
        $this->assertSame('override', $clicks[0]->utm_source);
        $this->assertSame('abc123', $clicks[0]->fbclid);
        $this->assertNotNull($clicks[0]->ip_hash);
        $this->assertNull($clicks[0]->metadata);
    }

    public function test_guest_attribution_survives_checkout_snapshot(): void
    {
        $cookie = app(AttributionCookie::class)->name();
        $link = $this->makeLink(code: 'guest-go', path: '/microscope-landing-page');
        $r = $this->get('/go/guest-go');
        $visitor = $this->cookieValue($r, $cookie);

        $product = $this->product();
        $user = User::factory()->create();
        $cart = Cart::query()->create(['user_id' => $user->id, 'expires_at' => now()->addDay()]);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]);

        $result = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart,
            $this->billing(),
            null,
            null,
            PaymentMethod::Online,
            new AttributionContext(visitorKey: $visitor, cartSessionId: 'guest-cart-1', authenticatedUserId: $user->id),
        );

        $order = $result['order'];
        $attr = OrderAttribution::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertTrue($attr->is_attributed);
        $this->assertSame($link->id, $attr->converting_tracking_link_id);
        $this->assertSame($link->code, $attr->converting_code);
        $this->assertSame($visitor, $attr->visitor_key);
        $this->assertNotEmpty($attr->first_touch);
        $this->assertNotEmpty($attr->last_touch);
    }

    public function test_checkout_without_attribution_and_fail_soft(): void
    {
        $product = $this->product();
        $user = User::factory()->create();
        $cart = Cart::query()->create(['user_id' => $user->id, 'expires_at' => now()->addDay()]);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]);

        $result = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart,
            $this->billing(),
        );
        $order = $result['order'];
        $this->assertInstanceOf(Order::class, $order);
        $attr = OrderAttribution::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertFalse($attr->is_attributed);

        $product2 = $this->product(sku: 'SKU-FAIL-SOFT');
        $cart2 = Cart::query()->create(['user_id' => $user->id, 'expires_at' => now()->addDay()]);
        CartItem::query()->create([
            'cart_id' => $cart2->id,
            'product_id' => $product2->id,
            'quantity' => 1,
            'unit_price' => $product2->price,
        ]);

        $mock = Mockery::mock(SnapshotOrderAttribution::class);
        $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('boom'));
        $this->app->instance(SnapshotOrderAttribution::class, $mock);
        $this->app->forgetInstance(CheckoutService::class);

        $result2 = app(CheckoutService::class)->createOrderFromCart($user, $cart2, $this->billing());
        $this->assertNotNull($result2['order']->id);
        $this->assertSame(OrderStatus::AwaitingPayment->value, $result2['order']->status);
    }

    public function test_order_paid_purchase_conversion_idempotent_and_isolated(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::AwaitingPayment->value,
            'subtotal' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 20,
            'tax_amount' => 0,
            'total' => 120,
            'currency' => 'EGP',
            'billing_address' => $this->billing(),
            'shipping_address' => $this->billing(),
        ]);
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'amount' => 120,
            'currency' => 'EGP',
            'status' => PaymentStatus::Completed->value,
            'paid_at' => now(),
        ]);
        OrderAttribution::query()->create([
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'visitor_key' => (string) Str::uuid(),
            'converting_platform' => 'instagram',
            'converting_campaign_id' => null,
            'converting_content_id' => null,
            'converting_tracking_link_id' => null,
            'converting_code' => 'ig-x',
            'first_touch' => ['tracking_code' => 'ig-x'],
            'last_touch' => ['tracking_code' => 'ig-x'],
            'attribution_model' => 'last_touch_v1',
            'window_days' => 30,
            'is_attributed' => true,
            'captured_at' => now(),
        ]);

        $paid = app(OrderFulfillmentService::class)->markPaid($order);
        $this->assertNotNull($paid->paid_at);

        $eventId = AttributionConversion::purchaseEventId((string) $order->uuid);
        $this->assertDatabaseHas('attribution_conversions', [
            'event_id' => $eventId,
            'event_name' => ConversionEventName::Purchase->value,
            'value' => '120.00',
            'currency' => 'EGP',
            'is_attributed' => 1,
        ]);
        $this->assertSame(1, AttributionConversion::query()->where('order_id', $order->id)->count());

        // Duplicate OrderPaid path must not duplicate conversion.
        app(OrderFulfillmentService::class)->markPaid($paid->fresh());
        event(new OrderPaid($paid->fresh()));
        $this->assertSame(1, AttributionConversion::query()->where('order_id', $order->id)->count());
        $this->assertNotNull($paid->fresh()->paid_at);

        // Unattributed purchase still records conversion.
        $order2 = Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::AwaitingPayment->value,
            'subtotal' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 50,
            'currency' => 'EGP',
            'billing_address' => $this->billing(),
            'shipping_address' => $this->billing(),
        ]);
        app(OrderFulfillmentService::class)->markPaid($order2);
        $c2 = AttributionConversion::query()->where('order_id', $order2->id)->firstOrFail();
        $this->assertFalse($c2->is_attributed);
        $this->assertSame('50.00', (string) $c2->value);
    }

    public function test_conversion_listener_is_queued_and_bosta_listener_independent(): void
    {
        $purchase = new RecordPurchaseConversionOnOrderPaid;
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $purchase);
        $this->assertTrue($purchase->afterCommit);

        $bosta = new CreateBostaShipmentOnOrderPaid(app(\App\Modules\Commerce\Application\Services\BostaShipmentService::class));
        $this->assertNotInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $bosta);

        // SalesChannelPlatform remains separate from AttributionPlatform.
        $this->assertSame('facebook', SalesChannelPlatform::Facebook->value);
        $this->assertSame('facebook', AttributionPlatform::Facebook->value);
        $this->assertFalse(enum_exists(SalesChannelPlatform::class) && AttributionPlatform::class === SalesChannelPlatform::class);
    }

    public function test_bearer_still_wins_and_go_does_not_assign_admin_user(): void
    {
        $admin = User::factory()->create(['email' => 'admin-attr@example.com']);
        $customer = User::factory()->create(['email' => 'customer-attr@example.com']);
        $this->actingAs($admin, 'web');

        $token = $customer->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'customer-attr@example.com');

        // Clear bearer so /go cannot attach Sanctum user; admin web session must not become ownership.
        $this->withoutHeader('Authorization');
        $this->makeLink(code: 'no-user', path: '/shop');
        $this->actingAs($admin, 'web')->get('/go/no-user')->assertRedirect();
        $session = AttributionSession::query()->latest('id')->firstOrFail();
        $this->assertNull($session->user_id);
    }

    public function test_sales_channel_platform_enum_untouched(): void
    {
        $this->assertTrue(SalesChannelPlatform::GoogleMerchant->isImplemented());
        $this->assertTrue(SalesChannelPlatform::YouTubeShopping->isImplemented());
        $this->assertFalse(SalesChannelPlatform::Facebook->isImplemented());
    }

    private function makeLink(
        string $code,
        string $path,
        AttributionPlatform $platform = AttributionPlatform::Instagram,
        bool $enabled = true,
        mixed $expiresAt = null,
    ): TrackingLink {
        $campaign = SocialCampaign::query()->create([
            'platform' => $platform,
            'name' => 'Campaign '.$code,
            'code' => 'camp-'.$code,
            'channel_kind' => ChannelKind::Paid,
            'status' => CampaignStatus::Active,
        ]);
        $content = SocialContent::query()->create([
            'campaign_id' => $campaign->id,
            'platform' => $platform,
            'content_type' => ContentType::InstagramReel,
            'title' => 'Content '.$code,
            'status' => CampaignStatus::Active,
            'destination_type' => DestinationType::LandingPage,
            'destination_path' => $path,
        ]);

        return TrackingLink::query()->create([
            'code' => $code,
            'platform' => $platform,
            'campaign_id' => $campaign->id,
            'social_content_id' => $content->id,
            'label' => $code,
            'destination_type' => DestinationType::LandingPage,
            'destination_path' => $path,
            'is_enabled' => $enabled,
            'status' => CampaignStatus::Active,
            'expires_at' => $expiresAt,
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'micro',
        ]);
    }

    private function product(string $sku = 'SKU-ATTR-1'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'slug' => 'slug-'.strtolower($sku),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 99,
            'currency' => 'EGP',
            'name' => ['en' => 'Attr Product', 'ar' => 'منتج'],
            'published_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function billing(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'buyer@example.com',
            'phone' => '01000000000',
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => 'Street 1',
        ];
    }

    private function cookieValue(\Illuminate\Testing\TestResponse $response, string $name): ?string
    {
        // Attribution cookie is excluded from EncryptCookies (opaque UUID).
        $cookie = $response->getCookie($name, decrypt: false);

        return $cookie?->getValue();
    }
}
