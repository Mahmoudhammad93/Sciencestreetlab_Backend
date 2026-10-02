<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Support\BostaPackageDetailsBuilder;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient;
use App\Modules\Commerce\Application\Services\BostaShipmentService;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BostaRealProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bosta.enabled' => true,
            'bosta.use_fake' => false,
            'bosta.api_contract_ready' => true,
            'bosta.api_url' => 'https://app.bosta.co',
            'bosta.api_key' => 'test-bosta-api-key-not-real',
            'bosta.http_retries' => 0,
        ]);
    }

    public function test_single_physical_product_sends_actual_name_and_items_count(): void
    {
        $order = $this->orderWithItems([
            [
                'name' => 'ميكروسكوب شارع العلوم',
                'sku' => 'SS-MICRO-001',
                'type' => ProductType::Kit,
                'qty' => 1,
                'unit_price' => 3720,
                'free_shipping' => false,
            ],
        ], total: 3720, paid: true, online: true);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order);

        $this->assertSame('ميكروسكوب شارع العلوم', $payload['specs']['packageDetails']['description']);
        $this->assertSame(1, $payload['specs']['packageDetails']['itemsCount']);
        $this->assertSame($order->order_number, $payload['businessReference']);
        $this->assertStringNotContainsString($order->order_number, $payload['specs']['packageDetails']['description']);
        $this->assertStringNotContainsString('Science Street Lab order', $payload['specs']['packageDetails']['description']);
        $this->assertSame(0, $payload['cod']);
        $this->assertArrayNotHasKey('deliveryImages', $payload);
        $this->assertArrayNotHasKey('products', $payload);
        $this->assertArrayNotHasKey('productInfo', $payload);
    }

    public function test_multi_physical_products_sum_quantities_and_list_names(): void
    {
        $order = $this->orderWithItems([
            [
                'name' => 'ميكروسكوب شارع العلوم',
                'sku' => 'SS-A',
                'type' => ProductType::Kit,
                'qty' => 2,
                'unit_price' => 100,
            ],
            [
                'name' => 'ذراع حفار هيدروليكي',
                'sku' => 'SS-B',
                'type' => ProductType::Kit,
                'qty' => 3,
                'unit_price' => 50,
            ],
        ], total: 350, paid: true, online: true);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order);
        $description = $payload['specs']['packageDetails']['description'];

        $this->assertSame(5, $payload['specs']['packageDetails']['itemsCount']);
        $this->assertSame('ميكروسكوب شارع العلوم × 2، ذراع حفار هيدروليكي × 3', $description);
        $this->assertSame(0, $payload['cod']);
    }

    public function test_digital_course_product_excluded_from_package_details(): void
    {
        $order = $this->orderWithItems([
            [
                'name' => 'ميكروسكوب شارع العلوم',
                'sku' => 'SS-MICRO-001',
                'type' => ProductType::Kit,
                'qty' => 1,
                'unit_price' => 2790,
            ],
            [
                'name' => 'كورس الميكروسكوب',
                'sku' => 'WP-25142',
                'type' => ProductType::Course,
                'qty' => 1,
                'unit_price' => 3720,
            ],
        ], total: 6510, paid: true, online: true);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order);

        $this->assertSame('ميكروسكوب شارع العلوم', $payload['specs']['packageDetails']['description']);
        $this->assertSame(1, $payload['specs']['packageDetails']['itemsCount']);
        $this->assertStringNotContainsString('كورس الميكروسكوب', $payload['specs']['packageDetails']['description']);
    }

    public function test_free_shipping_physical_product_still_included(): void
    {
        $order = $this->orderWithItems([
            [
                'name' => 'عدة مجانية الشحن',
                'sku' => 'SS-FREE-SHIP',
                'type' => ProductType::Kit,
                'qty' => 2,
                'unit_price' => 100,
                'free_shipping' => true,
            ],
        ], total: 200, paid: true, online: true);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order);

        $this->assertSame('عدة مجانية الشحن × 2', $payload['specs']['packageDetails']['description']);
        $this->assertSame(2, $payload['specs']['packageDetails']['itemsCount']);
    }

    public function test_order_item_name_preferred_over_changed_product_name(): void
    {
        $order = $this->orderWithItems([
            [
                'name' => 'اسم الطلب التاريخي',
                'sku' => 'SS-HIST',
                'type' => ProductType::Kit,
                'qty' => 1,
                'unit_price' => 111,
                'live_name' => 'اسم المنتج الحالي المتغير',
                'live_price' => 9999,
            ],
        ], total: 111, paid: true, online: true);

        $item = $order->items->first();
        $this->assertNotNull($item?->product_id);
        Product::query()->whereKey($item->product_id)->update([
            'name' => ['ar' => 'اسم المنتج الحالي المتغير', 'en' => 'Changed Live Name'],
            'price' => 9999,
        ]);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order->fresh(['items.product', 'user', 'payment']));

        $this->assertSame('اسم الطلب التاريخي', $payload['specs']['packageDetails']['description']);
        $this->assertStringNotContainsString('اسم المنتج الحالي المتغير', $payload['specs']['packageDetails']['description']);
        $this->assertStringNotContainsString('9999', $payload['specs']['packageDetails']['description']);
    }

    public function test_cod_payload_keeps_authoritative_total_and_real_product_description(): void
    {
        $order = $this->orderWithItems([
            [
                'name' => 'ميكروسكوب شارع العلوم',
                'sku' => 'SS-MICRO-001',
                'type' => ProductType::Kit,
                'qty' => 1,
                'unit_price' => 3720,
            ],
        ], total: 3800, paid: false, online: false, shipping: 80);

        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'cod',
            'amount' => 3800,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
            'payment_method' => 'cod',
        ]);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order->fresh(['items', 'user', 'payment']));

        $this->assertSame(3800, $payload['cod']);
        $this->assertSame('ميكروسكوب شارع العلوم', $payload['specs']['packageDetails']['description']);
        $this->assertSame(1, $payload['specs']['packageDetails']['itemsCount']);
        $this->assertEqualsWithDelta(3800.0, (float) $order->total, 0.001);
    }

    public function test_builder_reports_no_structured_product_or_image_fields(): void
    {
        $order = $this->orderWithItems([
            [
                'name' => 'ميكروسكوب شارع العلوم',
                'sku' => 'SS-MICRO-001',
                'type' => ProductType::Kit,
                'qty' => 1,
                'unit_price' => 3720,
            ],
        ], total: 3720, paid: true, online: true);

        $details = app(BostaPackageDetailsBuilder::class)->build($order);
        $this->assertSame(['description', 'itemsCount'], array_keys($details));
        $this->assertIsString($details['description']);
        $this->assertIsInt($details['itemsCount']);
    }

    public function test_http_create_sends_real_description_and_duplicate_ensure_is_idempotent(): void
    {
        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => 'city-cairo-id', 'name' => 'Cairo']]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => true,
                'data' => ['_id' => 'bosta-real-1', 'trackingNumber' => '1324999001', 'state' => 10],
            ], 200),
        ]);

        $order = $this->orderWithItems([
            [
                'name' => 'ميكروسكوب شارع العلوم',
                'sku' => 'SS-MICRO-001',
                'type' => ProductType::Kit,
                'qty' => 1,
                'unit_price' => 3720,
            ],
            [
                'name' => 'ذراع حفار هيدروليكي',
                'sku' => 'SS-ARM',
                'type' => ProductType::Kit,
                'qty' => 2,
                'unit_price' => 100,
            ],
        ], total: 3920, paid: true, online: true);

        $service = app(BostaShipmentService::class);
        $first = $service->ensureShipmentForOrder($order->fresh(['items.product', 'payment', 'bostaShipment']));
        $second = $service->ensureShipmentForOrder($order->fresh(['items.product', 'payment', 'bostaShipment']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('bosta-real-1', $first->external_shipment_id);
        $this->assertDatabaseCount('shipments', 1);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/api/v2/deliveries?apiVersion=1')) {
                return false;
            }
            $body = $request->data();
            $this->assertSame('ميكروسكوب شارع العلوم × 1، ذراع حفار هيدروليكي × 2', $body['specs']['packageDetails']['description']);
            $this->assertSame(3, $body['specs']['packageDetails']['itemsCount']);
            $this->assertSame(0, $body['cod']);

            return true;
        });

        // Only one create delivery POST (cities GET may also fire).
        $creates = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/api/v2/deliveries'))
            ->count();
        $this->assertSame(1, $creates);
    }

    /**
     * @param  list<array{name: string, sku: string, type: ProductType, qty: int, unit_price: float|int, free_shipping?: bool, live_name?: string, live_price?: float|int}>  $lines
     */
    private function orderWithItems(array $lines, float $total, bool $paid, bool $online, float $shipping = 0): Order
    {
        $user = User::factory()->create([
            'email' => 'bosta-details-'.uniqid().'@example.com',
            'name' => 'Bosta Details',
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => $paid ? OrderStatus::Paid->value : OrderStatus::Processing->value,
            'subtotal' => $total - $shipping,
            'discount_amount' => 0,
            'shipping_amount' => $shipping,
            'tax_amount' => 0,
            'total' => $total,
            'currency' => 'EGP',
            'paid_at' => $paid ? now() : null,
            'requires_delivery_fulfillment' => true,
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
        ]);

        if ($online && $paid) {
            Payment::query()->create([
                'order_id' => $order->id,
                'gateway' => 'fawaterak',
                'amount' => $total,
                'currency' => 'EGP',
                'status' => PaymentStatus::Completed->value,
                'payment_method' => 'online',
                'paid_at' => now(),
            ]);
        }

        foreach ($lines as $line) {
            $course = null;
            if ($line['type'] === ProductType::Course || $line['type'] === ProductType::Kit) {
                $course = Course::query()->create([
                    'slug' => 'c-'.uniqid(),
                    'access_type' => AccessType::Paid,
                    'is_published' => true,
                    'published_at' => now(),
                    'title' => ['ar' => 'د', 'en' => 'c'],
                    'short_description' => ['ar' => 'م', 'en' => 's'],
                    'description' => ['ar' => 'و', 'en' => 'd'],
                ]);
            }

            $product = Product::query()->create([
                'sku' => $line['sku'].'-'.uniqid(),
                'slug' => 'p-'.uniqid(),
                'type' => $line['type'],
                'status' => ProductStatus::Published,
                'price' => $line['live_price'] ?? $line['unit_price'],
                'currency' => 'EGP',
                'course_id' => $course?->id,
                'free_shipping' => (bool) ($line['free_shipping'] ?? false),
                'published_at' => now(),
                'name' => [
                    'ar' => $line['live_name'] ?? $line['name'],
                    'en' => $line['live_name'] ?? $line['name'],
                ],
            ]);

            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $line['name'],
                'product_sku' => $product->sku,
                'quantity' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'total_price' => $line['unit_price'] * $line['qty'],
                'metadata' => [
                    'product_type' => $line['type']->value,
                    'course_id' => $course?->id,
                    'free_shipping' => (bool) ($line['free_shipping'] ?? false),
                ],
            ]);
        }

        return $order->fresh(['items.product', 'user', 'payment']) ?? $order;
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
            'city' => 'Cairo',
            'country' => 'EG',
            'address' => '12 Nile St',
            'district' => 'Nasr City',
            'district_name' => 'Nasr City',
            'bosta_city_id' => 'city-cairo-id',
        ];
    }
}
