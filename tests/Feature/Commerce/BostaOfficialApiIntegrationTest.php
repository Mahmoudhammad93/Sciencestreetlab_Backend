<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Payment\FawaterakGateway;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\BostaStatusMapper;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class BostaOfficialApiIntegrationTest extends TestCase
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
            'bosta.webhook_auth_ready' => true,
            'bosta.webhook_signature_ready' => true,
            'bosta.webhook_secret' => 'whsec-test',
            'bosta.webhook_auth_header' => 'Authorization',
            'bosta.http_retries' => 0,
        ]);
    }

    public function test_http_bosta_create_delivery_payload_and_headers(): void
    {
        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [
                    ['_id' => 'city-cairo-id', 'name' => 'Cairo', 'nameAr' => 'القاهرة', 'alias' => 'القاهرة'],
                ]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => true,
                'message' => 'Done successfully.',
                'data' => [
                    '_id' => 'bosta-del-1',
                    'trackingNumber' => '991122',
                    'state' => 10,
                ],
            ], 200),
        ]);

        $order = $this->paidKitOrder(total: 250.0);

        $result = app(HttpBostaClient::class)->createShipment($order);

        $this->assertSame('bosta-del-1', $result['external_shipment_id']);
        $this->assertSame('991122', $result['tracking_number']);

        Http::assertSent(function (Request $request) use ($order): bool {
            if (! str_contains($request->url(), '/api/v2/deliveries?apiVersion=1')) {
                return false;
            }

            $this->assertSame('test-bosta-api-key-not-real', $request->header('Authorization')[0] ?? null);
            $this->assertFalse(str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Bearer '));
            $this->assertSame('application/json', $request->header('Content-Type')[0] ?? null);

            $body = $request->data();
            $this->assertSame(10, $body['type']);
            $this->assertSame(0, $body['cod']);
            $this->assertSame($order->order_number, $body['businessReference']);
            $this->assertSame('Ada', $body['receiver']['firstName']);
            $this->assertSame('Lovelace', $body['receiver']['lastName']);
            $this->assertSame('01012345678', $body['receiver']['phone']);
            $this->assertSame('ada@example.com', $body['receiver']['email']);
            $this->assertSame('city-cairo-id', $body['dropOffAddress']['city']);
            $this->assertSame('12 Nile Street Ave', $body['dropOffAddress']['firstLine']);
            $this->assertSame('Nasr City', $body['dropOffAddress']['districtName']);
            $this->assertSame(2, $body['specs']['packageDetails']['itemsCount']);
            $this->assertSame('Science Kit × 2', $body['specs']['packageDetails']['description']);
            $this->assertStringNotContainsString('Science Street Lab order', $body['specs']['packageDetails']['description']);
            $this->assertGreaterThan(0, (float) $order->total);

            return true;
        });
    }

    public function test_prepaid_fawaterak_order_sends_cod_zero_even_when_total_positive(): void
    {
        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => 'city-cairo-id', 'name' => 'Cairo']]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => true,
                'data' => ['_id' => 'del-2', 'trackingNumber' => '1', 'state' => 10],
            ], 200),
        ]);

        $order = $this->paidKitOrder(total: 999.5);
        $this->assertGreaterThan(0, (float) $order->total);

        $payload = app(HttpBostaClient::class)->buildCreateDeliveryPayload($order);
        $this->assertSame(0, $payload['cod']);
        $this->assertArrayNotHasKey('escrowInfo', $payload);
    }

    public function test_http_non_2xx_is_retryable_failure(): void
    {
        $order = $this->paidKitOrder();

        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => 'city-cairo-id', 'name' => 'Cairo']]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => false,
                'message' => 'City Not Found',
                'errorCode' => 3001,
            ], 400),
        ]);

        try {
            app(HttpBostaClient::class)->createShipment($order);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }
    }

    public function test_http_success_false_with_2xx_throws(): void
    {
        $order = $this->paidKitOrder();

        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => 'city-cairo-id', 'name' => 'Cairo']]],
            ], 200),
            'https://app.bosta.co/api/v2/deliveries*' => Http::response([
                'success' => false,
                'message' => 'rejected',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected');
        app(HttpBostaClient::class)->createShipment($order);
    }

    public function test_missing_city_identifier_returns_clear_configuration_error(): void
    {
        Http::fake([
            'https://app.bosta.co/api/v2/cities' => Http::response([
                'success' => true,
                'data' => ['list' => [['_id' => 'other', 'name' => 'Alexandria']]],
            ], 200),
        ]);

        $order = $this->paidKitOrder();
        $order->update([
            'shipping_address' => array_merge($order->shipping_address, ['city' => 'UnknownVille']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('city, zoneId, or districtId');
        app(HttpBostaClient::class)->createShipment($order->fresh());
    }

    public function test_official_numeric_state_mapping_for_send_type(): void
    {
        $mapper = new BostaStatusMapper;

        $this->assertSame(ShipmentStatus::Created, $mapper->map('10', 'SEND'));
        $this->assertSame(ShipmentStatus::Created, $mapper->map('20', 'SEND'));
        $this->assertSame(ShipmentStatus::PickedUp, $mapper->map('21', 'SEND'));
        $this->assertSame(ShipmentStatus::InTransit, $mapper->map('24', 'SEND'));
        $this->assertSame(ShipmentStatus::InTransit, $mapper->map('30', 'SEND'));
        $this->assertSame(ShipmentStatus::OutForDelivery, $mapper->map('41', 'SEND'));
        $this->assertSame(ShipmentStatus::Delivered, $mapper->map('45', 'SEND'));
        $this->assertSame(ShipmentStatus::Unknown, $mapper->map('47', 'SEND'));
        $this->assertSame(ShipmentStatus::Cancelled, $mapper->map('48', 'SEND'));
        $this->assertSame(ShipmentStatus::Cancelled, $mapper->map('49', 'SEND'));
        $this->assertSame(ShipmentStatus::Unknown, $mapper->map('999', 'SEND'));
    }

    public function test_fawaterak_amount_mismatch_does_not_complete_payment(): void
    {
        config([
            'bosta.use_fake' => true,
            'fawaterak.vendor_key' => 'vendor-secret',
        ]);

        $order = $this->unpaidKitOrder(total: 200);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'inv-1',
            'amount' => 200,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
        ]);

        $payload = $this->signedPaidWebhook('inv-1', amount: 150, currency: 'EGP');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('amount');
        try {
            app(FawaterakGateway::class)->handleCallback($payload);
        } finally {
            $this->assertSame(PaymentStatus::Processing->value, $payment->fresh()->status);
            $this->assertNull($order->fresh()->paid_at);
            $this->assertDatabaseCount('shipments', 0);
        }
    }

    public function test_fawaterak_currency_mismatch_does_not_complete_payment(): void
    {
        config([
            'bosta.use_fake' => true,
            'fawaterak.vendor_key' => 'vendor-secret',
        ]);

        $order = $this->unpaidKitOrder(total: 200);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'inv-2',
            'amount' => 200,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
        ]);

        $payload = $this->signedPaidWebhook('inv-2', amount: 200, currency: 'USD');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('currency');
        try {
            app(FawaterakGateway::class)->handleCallback($payload);
        } finally {
            $this->assertSame(PaymentStatus::Processing->value, $payment->fresh()->status);
            $this->assertNull($order->fresh()->paid_at);
            $this->assertDatabaseCount('shipments', 0);
        }
    }

    public function test_duplicate_fawaterak_webhook_completes_once_and_creates_one_bosta_shipment(): void
    {
        config([
            'bosta.use_fake' => true,
            'fawaterak.vendor_key' => 'vendor-secret',
        ]);

        $order = $this->unpaidKitOrder(total: 200);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'inv-3',
            'amount' => 200,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
        ]);

        $payload = $this->signedPaidWebhook('inv-3', amount: 200, currency: 'EGP');

        app(FawaterakGateway::class)->handleCallback($payload);
        app(FawaterakGateway::class)->handleCallback($payload);

        $this->assertSame(PaymentStatus::Completed->value, $payment->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertNotNull($order->fresh()->bostaShipment?->external_shipment_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function signedPaidWebhook(string $invoiceId, float $amount, string $currency): array
    {
        $payload = [
            'invoice_id' => $invoiceId,
            'invoice_key' => 'key-'.$invoiceId,
            'payment_method' => 'card',
            'invoice_status' => 'paid',
            'referenceNumber' => 'ref-'.$invoiceId,
            'paidAmount' => $amount,
            'currency' => $currency,
        ];
        $payload['hashKey'] = hash_hmac(
            'sha256',
            sprintf('InvoiceId=%s&InvoiceKey=%s&PaymentMethod=%s', $invoiceId, $payload['invoice_key'], $payload['payment_method']),
            'vendor-secret'
        );

        return $payload;
    }

    private function paidKitOrder(float $total = 250.0): Order
    {
        $order = $this->unpaidKitOrder($total);
        $order->update([
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
        ]);

        return $order->fresh(['items', 'user']) ?? $order;
    }

    private function unpaidKitOrder(float $total = 250.0): Order
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
        $course = Course::query()->create([
            'slug' => 'kit-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Kit', 'ar' => 'عدة'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product = Product::query()->create([
            'sku' => 'KIT-'.uniqid(),
            'slug' => 'kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => $total / 2,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Science Kit', 'ar' => 'عدة'],
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::AwaitingPayment->value,
            'subtotal' => $total,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => $total,
            'currency' => 'EGP',
            'requires_delivery_fulfillment' => true,
            'billing_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => '12 Nile Street Ave',
                'district' => 'Nasr City',
            ],
            'shipping_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => '12 Nile Street Ave',
                'district' => 'Nasr City',
            ],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Science Kit',
            'product_sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => $total / 2,
            'total_price' => $total,
            'metadata' => ['product_type' => ProductType::Kit->value, 'course_id' => $course->id],
        ]);

        return $order->fresh(['items', 'user']) ?? $order;
    }
}
