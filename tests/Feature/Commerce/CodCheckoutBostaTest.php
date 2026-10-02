<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\BostaShipmentService;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Support\OrderPaymentEligibility;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CodCheckoutBostaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
            'bosta.api_contract_ready' => false,
            'commerce.payment_gateway' => 'mock',
        ]);

        $this->seed(\Database\Seeders\ShippingRateSeeder::class);
    }

    public function test_cod_checkout_creates_processing_order_without_fawaterak_or_paid_at(): void
    {
        [$user, $product] = $this->kitProduct();
        Sanctum::actingAs($user);

        $this->addToCart($product);

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $orderId = (int) $response->json('data.id');
        $order = Order::query()->findOrFail($orderId);

        $this->assertSame(OrderStatus::Processing->value, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertDatabaseHas('payments', [
            'order_id' => $orderId,
            'gateway' => 'cod',
            'payment_method' => PaymentMethod::CashOnDelivery->value,
            'status' => PaymentStatus::Pending->value,
        ]);
        $this->assertSame(0, Payment::query()->where('order_id', $orderId)->where('gateway', 'fawaterak')->count());
        $this->assertNull(Payment::query()->where('order_id', $orderId)->value('paid_at'));

        $eligibility = app(OrderPaymentEligibility::class)->forOrder($order);
        $this->assertFalse($eligibility['payment_required']);
        $this->assertFalse($eligibility['payment_retry_allowed']);
        $this->assertTrue($eligibility['is_cod']);
        $this->assertSame(PaymentMethod::CashOnDelivery->value, $eligibility['payment_method']);
        $this->assertFalse($eligibility['is_paid']);

        $this->postJson('/api/v1/checkout/'.$orderId.'/pay')
            ->assertStatus(422);

        $show = $this->getJson('/api/v1/orders/'.$order->order_number)->assertOk();
        $show->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.payment_retry_allowed', false)
            ->assertJsonPath('data.payment_method', PaymentMethod::CashOnDelivery->value)
            ->assertJsonPath('data.is_cod', true)
            ->assertJsonPath('data.paid_at', null);
    }

    public function test_cod_checkout_creates_exactly_one_bosta_shipment_with_final_total_cod(): void
    {
        $createCalls = (object) ['n' => 0, 'lastCod' => null];
        $this->bindCountingBostaClient($createCalls);

        [$user, $product] = $this->kitProduct(price: 2790);
        Sanctum::actingAs($user);

        $coupon = Coupon::query()->create([
            'code' => 'CODHEAVY',
            'type' => 'fixed',
            'value' => 2776.05,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'max_uses' => 100,
            'used_count' => 0,
        ]);

        $this->addToCart($product);
        $this->postJson('/api/v1/cart/coupon', ['code' => 'CODHEAVY'])->assertOk();

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cash_on_delivery',
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $order = Order::query()->findOrFail((int) $response->json('data.id'));
        $this->assertEqualsWithDelta(93.95, (float) $order->total, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $order->shipping_amount, 0.001);
        $this->assertEqualsWithDelta(2776.05, (float) $order->discount_amount, 0.001);
        $this->assertSame(1, $createCalls->n);
        $this->assertEqualsWithDelta(93.95, (float) $createCalls->lastCod, 0.001);

        $this->assertDatabaseCount('shipments', 1);
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(ShipmentProvider::Bosta, $shipment->provider);
        $this->assertNotNull($shipment->external_shipment_id);
        $this->assertNotNull($shipment->tracking_number);
        $this->assertSame($coupon->id, $order->coupon_id);

        // Duplicate ensure must not create another external delivery.
        app(BostaShipmentService::class)->ensureShipmentForOrder($order->fresh(['items', 'payment']));
        app(BostaShipmentService::class)->ensureShipmentForOrder($order->fresh(['items', 'payment']));
        $this->assertSame(1, $createCalls->n);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_cod_bosta_http_payload_uses_order_total_and_official_district(): void
    {
        config([
            'bosta.use_fake' => false,
            'bosta.api_contract_ready' => true,
            'bosta.api_url' => 'https://app.bosta.co',
            'bosta.api_key' => 'test-bosta-api-key-not-real',
            'bosta.http_retries' => 0,
        ]);

        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => '0064Qb0OgcA', 'name' => 'Giza', 'nameAr' => 'الجيزه']]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => true,
                'data' => ['_id' => 'cod-del-1', 'trackingNumber' => '1324000001', 'state' => 10],
            ], 200),
        ]);

        [$user, $product] = $this->kitProduct(price: 100);
        $cart = $this->cartFor($user, $product);
        $order = app(CheckoutService::class)->createOrderFromCart(
            $user,
            $cart,
            $this->address(),
            $this->address(),
            null,
            PaymentMethod::CashOnDelivery,
        )['order'];

        // Bypass Fake; call Http client directly for payload assertion.
        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order->fresh(['items', 'payment', 'user']));
        $this->assertSame(180, $payload['cod']);
        $this->assertSame(10, $payload['type']);
        $this->assertSame($order->order_number, $payload['businessReference']);
        $this->assertSame('ULL4DLjuJ3t', $payload['dropOffAddress']['districtId']);
        $this->assertSame('0064Qb0OgcA', $payload['dropOffAddress']['city']);
        $this->assertEqualsWithDelta(80.0, (float) $order->shipping_amount, 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $order->total, 0.001);
    }

    public function test_cod_checkout_rejects_missing_official_district(): void
    {
        [$user, $product] = $this->kitProduct();
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $address = $this->address();
        unset(
            $address['district'],
            $address['district_name'],
            $address['district_id'],
            $address['bosta_district_id'],
        );

        $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'billing_address' => $address,
            'shipping_address' => $address,
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Shipping district/area is required for physical delivery.']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_online_unpaid_shows_pay_now_and_does_not_create_bosta(): void
    {
        [$user, $product] = $this->kitProduct();
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'online',
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $order = Order::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(OrderStatus::AwaitingPayment->value, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('payments', 0);

        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.payment_required', true)
            ->assertJsonPath('data.payment_retry_allowed', true)
            ->assertJsonPath('data.is_cod', false)
            ->assertJsonPath('data.payment_method', PaymentMethod::Online->value);
    }

    public function test_online_paid_bosta_cod_is_zero(): void
    {
        config([
            'bosta.use_fake' => false,
            'bosta.api_contract_ready' => true,
            'bosta.api_url' => 'https://app.bosta.co',
            'bosta.api_key' => 'test-bosta-api-key-not-real',
            'bosta.http_retries' => 0,
        ]);

        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => '0064Qb0OgcA', 'name' => 'Giza']]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => true,
                'data' => ['_id' => 'paid-del', 'trackingNumber' => '1', 'state' => 10],
            ], 200),
        ]);

        [$user, $product] = $this->kitProduct(price: 250);
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $checkout = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'online',
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $orderId = (int) $checkout->json('data.id');
        $pay = $this->postJson('/api/v1/checkout/'.$orderId.'/pay')->assertOk();
        $paymentId = (int) $pay->json('data.payment_id');
        $this->postJson('/api/v1/payments/mock/'.$paymentId.'/complete')->assertOk();

        $order = Order::query()->findOrFail($orderId);
        $this->assertNotNull($order->paid_at);

        Http::assertSent(function (Request $request) use ($order): bool {
            if (! str_contains($request->url(), '/api/v2/deliveries')) {
                return true;
            }
            $body = $request->data();
            $this->assertSame(0, $body['cod']);
            $this->assertSame($order->order_number, $body['businessReference']);

            return true;
        });

        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.payment_retry_allowed', false)
            ->assertJsonPath('data.is_paid', true);
    }

    public function test_bearer_customer_wins_over_admin_web_session_for_cod_order_show(): void
    {
        $admin = User::factory()->create(['email' => 'admin-cod@sciencestreetlab.com']);
        [$customer, $product] = $this->kitProduct();
        $cart = $this->cartFor($customer, $product);
        $order = app(CheckoutService::class)->createOrderFromCart(
            $customer,
            $cart,
            $this->address(),
            $this->address(),
            null,
            PaymentMethod::CashOnDelivery,
        )['order'];

        $this->actingAs($admin, 'web');
        $this->assertSame($admin->id, Auth::guard('web')->id());

        $token = $customer->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.user_id', $customer->id)
            ->assertJsonPath('data.is_cod', true);
    }

    public function test_cod_cancel_allowed_before_external_bosta_and_blocked_after(): void
    {
        config(['bosta.enabled' => false]);

        [$user, $product] = $this->kitProduct();
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $order = Order::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(OrderStatus::Processing->value, $order->status);
        $this->assertDatabaseCount('shipments', 0);

        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.cancellation_allowed', true);

        // Simulate external Bosta shipment after checkout.
        Shipment::query()->create([
            'order_id' => $order->id,
            'provider' => ShipmentProvider::Bosta->value,
            'external_shipment_id' => 'live-bosta-ext-1',
            'tracking_number' => '1324454999',
            'status' => 'created',
        ]);

        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.cancellation_allowed', false);

        $this->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This order already has a live Bosta shipment. Contact support to cancel — automatic cancellation is blocked to avoid orphaning the delivery.']);

        $this->assertSame(OrderStatus::Processing->value, $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'external_shipment_id' => 'live-bosta-ext-1',
        ]);
    }

    public function test_cod_cancel_succeeds_when_no_external_shipment(): void
    {
        config(['bosta.enabled' => false]);

        [$user, $product] = $this->kitProduct();
        Sanctum::actingAs($user);
        $this->addToCart($product);

        $response = $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod',
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ])->assertCreated();

        $order = Order::query()->findOrFail((int) $response->json('data.id'));

        $this->postJson('/api/v1/orders/'.$order->order_number.'/cancel', [
            'reason' => 'غيرت رأيي',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($order->fresh()->cancelled_at);
        $this->assertDatabaseCount('shipments', 0);
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function kitProduct(float $price = 250): array
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'slug' => 'cod-kit-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit', 'ar' => 'عدة'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'COD-KIT-'.uniqid(),
            'slug' => 'cod-kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => $price,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Science Kit', 'ar' => 'عدة'],
        ]);

        return [$user, $product];
    }

    private function addToCart(Product $product): void
    {
        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertCreated();
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

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'phone' => '01012345678',
            'city' => 'Giza',
            'country' => 'EG',
            'address' => 'Arab ElHesar St 1',
            'district' => 'ElSaf',
            'district_name' => 'ElSaf',
            'district_id' => 'ULL4DLjuJ3t',
            'bosta_district_id' => 'ULL4DLjuJ3t',
            'bosta_city_id' => '0064Qb0OgcA',
            'zone_id' => 'gl7gjgDSq5N',
            'bosta_zone_id' => 'gl7gjgDSq5N',
        ];
    }

    private function bindCountingBostaClient(object $counter): void
    {
        $this->app->instance(
            \App\Modules\Commerce\Domain\Contracts\BostaClientInterface::class,
            new class($counter) implements \App\Modules\Commerce\Domain\Contracts\BostaClientInterface
            {
                public function __construct(private object $counter) {}

                public function createShipment(\App\Modules\Commerce\Infrastructure\Persistence\Models\Order $order): array
                {
                    $this->counter->n++;
                    $amount = round((float) $order->total, 2);
                    $cod = fmod($amount, 1.0) === 0.0 ? (int) $amount : $amount;
                    $this->counter->lastCod = $cod;

                    return [
                        'external_shipment_id' => 'cod-fake-'.$order->id,
                        'tracking_number' => 'TRK-COD-'.$order->order_number,
                        'tracking_url' => 'https://bosta.example/track/'.$order->id,
                        'provider_status' => '10',
                        'raw' => ['cod' => $cod],
                        'request' => ['cod' => $cod, 'type' => 10],
                    ];
                }
            }
        );
        $this->app->forgetInstance(BostaShipmentService::class);
        $this->app->forgetInstance(CheckoutService::class);
    }
}
