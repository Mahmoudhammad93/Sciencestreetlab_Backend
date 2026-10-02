<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Services\ShippingPricingService;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\ShippingRateGroup;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Database\Seeders\ShippingRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ShippingPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'commerce.payment_gateway' => 'mock',
        ]);

        $this->seed(ShippingRateSeeder::class);
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public static function cityRateProvider(): array
    {
        return [
            'cairo' => ['FceDyHXwpSYYF9zGW', 80.0],
            'giza' => ['0064Qb0OgcA', 80.0],
            'alexandria' => ['Jrb6X6ucjiYgMP4T7', 85.0],
            'beheira' => ['g3GchTSmCgR2JynsJ', 85.0],
            'dakahlia' => ['RrDhS8YYsXAwZ9Zfo', 90.0],
            'sharqia' => ['6ExcoGbpYHnggP8JD', 90.0],
            'suez' => ['PickurJ5uJZ9rDTHW', 90.0],
            'fayoum' => ['BW5MiNxEirB7tuz2y', 105.0],
            'bani_suif' => ['LzbbvTzZ7D2CgE2PL', 105.0],
            'menya' => ['si6eLnKjXqTFTMBj9', 105.0],
            'assuit' => ['7mDPAohM3ArSZmWTm', 105.0],
            'sohag' => ['n3EENg2adhuR9xBZK', 105.0],
            'qena' => ['vfTHTes3uGjAszgtg', 120.0],
            'luxor' => ['wgYEdH2WMzxGE2Ztp', 120.0],
            'aswan' => ['kLvZ5JY6LJPL5chzN', 120.0],
            'red_sea' => ['r5TscLCNSjR2GimxQ', 120.0],
            'matrouh' => ['KBpGiRZJMIx', 120.0],
            'north_coast' => ['2hGtNLfRgqGrJjnW9', 125.0],
            'south_sinai' => ['nG_c44vHQht', 145.0],
            'new_valley' => ['w4yDVHVJWqa4HpbzA', 145.0],
        ];
    }

    #[DataProvider('cityRateProvider')]
    public function test_city_maps_to_owner_rate(string $cityId, float $expected): void
    {
        $quote = app(ShippingPricingService::class)->quoteForDestination([
            'bosta_city_id' => $cityId,
            'bosta_district_id' => 'any-district',
        ]);

        $this->assertSame($expected, $quote['amount']);
        $this->assertSame('city', $quote['resolution']);
    }

    public function test_all_free_shipping_physical_cart_is_zero(): void
    {
        [$user, $product] = $this->kitProduct(price: 100, freeShipping: true);
        $cart = $this->cartFor($user, $product);

        $quote = app(ShippingPricingService::class)->quoteForCart($cart, [
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
        ]);

        $this->assertSame(0.0, $quote['amount']);
        $this->assertTrue($quote['free']);
        $this->assertSame('all_products_free_shipping', $quote['reason']);
    }

    public function test_mixed_free_and_paid_uses_destination_rate_once(): void
    {
        [$user, $free] = $this->kitProduct(price: 50, freeShipping: true, sku: 'FREE');
        [, $paid] = $this->kitProduct(price: 70, freeShipping: false, sku: 'PAID');
        $cart = $this->cartFor($user, $free);
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $paid->id,
            'quantity' => 1,
            'unit_price' => $paid->price,
        ]);
        $cart = $cart->fresh(['items.product']);

        $quote = app(ShippingPricingService::class)->quoteForCart($cart, [
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
        ]);

        $this->assertSame(80.0, $quote['amount']);
    }

    public function test_digital_only_shipping_is_zero(): void
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'dig-'.uniqid(),
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
            'free_shipping' => false,
            'published_at' => now(),
            'name' => ['en' => 'Course', 'ar' => 'دورة'],
        ]);
        $cart = $this->cartFor($user, $product);

        $quote = app(ShippingPricingService::class)->quoteForCart($cart, null);
        $this->assertSame(0.0, $quote['amount']);
        $this->assertSame('digital_only', $quote['reason']);
    }

    public function test_unmapped_location_blocks_checkout_and_quote(): void
    {
        [$user, $product] = $this->kitProduct();
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $address = $this->address('UNMAPPED_CITY_ID_XYZ');

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_address' => $address,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'shipping_unavailable');

        $this->postJson('/api/v1/checkout', [
            'payment_method' => 'online',
            'billing_address' => $address,
            'shipping_address' => $address,
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_coupon_plus_shipping_totals_and_giza_example(): void
    {
        [$user, $product] = $this->kitProduct(price: 2790);
        Sanctum::actingAs($user);

        Coupon::query()->create([
            'code' => 'HEAVYSHIP',
            'type' => 'fixed',
            'value' => 2776.05,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'max_uses' => 10,
            'used_count' => 0,
        ]);

        $this->addToCart($product);
        $this->postJson('/api/v1/cart/coupon', ['code' => 'HEAVYSHIP'])->assertOk();

        $quote = $this->postJson('/api/v1/checkout/quote', [
            'shipping_address' => $this->address('0064Qb0OgcA'),
        ])->assertOk();

        $quote->assertJsonPath('data.subtotal', 2790);
        $quote->assertJsonPath('data.discount', 2776.05);
        $quote->assertJsonPath('data.shipping', 80);
        $this->assertEqualsWithDelta(93.95, (float) $quote->json('data.total'), 0.001);

        $checkout = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'billing_address' => $this->address('0064Qb0OgcA'),
            'shipping_address' => $this->address('0064Qb0OgcA'),
        ])->assertCreated();

        $order = Order::query()->findOrFail((int) $checkout->json('data.id'));
        $this->assertEqualsWithDelta(80.0, (float) $order->shipping_amount, 0.001);
        $this->assertEqualsWithDelta(93.95, (float) $order->total, 0.001);
        $this->assertSame('cairo_giza', $order->shipping_snapshot['rate_code'] ?? null);
    }

    public function test_online_paid_bosta_cod_is_zero_while_order_total_includes_shipping(): void
    {
        config([
            'bosta.use_fake' => false,
            'bosta.api_contract_ready' => true,
            'bosta.api_url' => 'https://app.bosta.co',
            'bosta.api_key' => 'test-key',
            'bosta.http_retries' => 0,
        ]);
        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => 'FceDyHXwpSYYF9zGW', 'name' => 'Cairo']]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => true,
                'data' => ['_id' => 'del-ship', 'trackingNumber' => '1', 'state' => 10],
            ], 200),
        ]);

        [$user, $product] = $this->kitProduct(price: 100);
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $checkout = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'online',
            'billing_address' => $this->address('FceDyHXwpSYYF9zGW'),
            'shipping_address' => $this->address('FceDyHXwpSYYF9zGW'),
        ])->assertCreated();

        $orderId = (int) $checkout->json('data.id');
        $order = Order::query()->findOrFail($orderId);
        $this->assertEqualsWithDelta(180.0, (float) $order->total, 0.001); // 100 + 80

        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay')->assertOk();
        $this->postJson('/api/v1/payments/mock/'.((int) $pay->json('data.payment_id')).'/complete')
            ->assertOk();

        Http::assertSent(function (Request $request) use ($order): bool {
            if (! str_contains($request->url(), '/api/v2/deliveries')) {
                return true;
            }
            $this->assertSame(0, $request->data()['cod'] ?? null);
            $this->assertSame($order->order_number, $request->data()['businessReference'] ?? null);

            return true;
        });
    }

    public function test_cod_bosta_amount_includes_shipping(): void
    {
        [$user, $product] = $this->kitProduct(price: 100);
        $cart = $this->cartFor($user, $product);

        $order = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart,
            $this->address('FceDyHXwpSYYF9zGW'),
            $this->address('FceDyHXwpSYYF9zGW'),
            null,
            PaymentMethod::CashOnDelivery,
        )['order'];

        $this->assertEqualsWithDelta(180.0, (float) $order->total, 0.001);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload(
            $order->fresh(['items', 'payment', 'user'])
        );
        $this->assertSame(180, $payload['cod']);
    }

    public function test_fawaterak_invoice_amount_includes_shipping(): void
    {
        [$user, $product] = $this->kitProduct(price: 13.95);
        $cart = $this->cartFor($user, $product);

        $order = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart,
            $this->address('0064Qb0OgcA'),
            $this->address('0064Qb0OgcA'),
            null,
            PaymentMethod::Online,
        )['order'];

        $this->assertEqualsWithDelta(93.95, (float) $order->total, 0.001);

        $payment = \App\Modules\Commerce\Infrastructure\Persistence\Models\Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => 'pending',
        ]);

        $payload = app(\App\Modules\Commerce\Infrastructure\Payment\FawaterakGateway::class)
            ->buildCreateInvoicePayload($order, $payment, 'TEST-REF');

        $this->assertSame('93.95', $payload['cartTotal']);
        $this->assertSame('93.95', $payload['cartItems'][0]['price']);
    }

    public function test_historical_order_unaffected_by_rate_and_free_shipping_changes(): void
    {
        [$user, $product] = $this->kitProduct(price: 100, freeShipping: false);
        $cart = $this->cartFor($user, $product);
        $order = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart,
            $this->address('FceDyHXwpSYYF9zGW'),
            $this->address('FceDyHXwpSYYF9zGW'),
            null,
            PaymentMethod::Online,
        )['order'];

        $this->assertEqualsWithDelta(80.0, (float) $order->shipping_amount, 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $order->total, 0.001);
        $snapshot = $order->shipping_snapshot;
        $itemMeta = $order->items->first()?->metadata;

        ShippingRateGroup::query()->where('code', 'cairo_giza')->update(['price' => 999]);
        $product->update(['free_shipping' => true]);

        $order->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $order->shipping_amount, 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $order->total, 0.001);
        $this->assertSame($snapshot, $order->shipping_snapshot);
        $this->assertFalse((bool) ($itemMeta['free_shipping'] ?? true));
    }

    public function test_district_override_beats_city_rate(): void
    {
        $group = ShippingRateGroup::query()->where('code', 'north_coast')->firstOrFail();
        $group->locations()->create([
            'scope_type' => 'district',
            'bosta_location_id' => 'DISTRICT_OVERRIDE_1',
            'bosta_location_name' => 'Override District',
            'is_active' => true,
        ]);

        $quote = app(ShippingPricingService::class)->quoteForDestination([
            'bosta_city_id' => 'FceDyHXwpSYYF9zGW', // would be 80
            'bosta_district_id' => 'DISTRICT_OVERRIDE_1', // north coast 125
        ]);

        $this->assertSame(125.0, $quote['amount']);
        $this->assertSame('district', $quote['resolution']);
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function kitProduct(float $price = 100, bool $freeShipping = false, string $sku = 'KIT'): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'ship-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit', 'ar' => 'عدة'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => $sku.'-'.uniqid(),
            'slug' => strtolower($sku).'-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => $price,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'free_shipping' => $freeShipping,
            'published_at' => now(),
            'name' => ['en' => 'Science Kit', 'ar' => 'عدة'],
        ]);

        return [$user, $product];
    }

    private function cartFor(User $user, Product $product): Cart
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

    private function addToCart(Product $product): void
    {
        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertCreated();
    }

    /**
     * @return array<string, string>
     */
    private function address(string $bostaCityId): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => '12 Nile St',
            'district' => 'Nasr City',
            'district_name' => 'Nasr City',
            'district_id' => 'district-nasr',
            'bosta_district_id' => 'district-nasr',
            'bosta_city_id' => $bostaCityId,
        ];
    }
}
