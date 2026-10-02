<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Application\Support\OrderPaymentEligibility;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Payment\FawaterakGateway;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class OrderPaymentRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'commerce.payment_gateway' => 'fawaterak',
            'fawaterak.api_key' => 'test-fawaterak-key',
            'fawaterak.vendor_key' => 'vendor-secret',
            'fawaterak.base_url' => 'https://fawaterak.test',
            'fawaterak.currency' => 'EGP',
            'fawaterak.min_amount' => 5.01,
            'bosta.enabled' => true,
            'bosta.use_fake' => true,
        ]);

        $this->app->forgetInstance(\App\Shared\Contracts\PaymentGatewayInterface::class);
        $this->app->forgetInstance(FawaterakGateway::class);
    }

    public function test_failed_payment_exposes_retry_flags_and_keeps_awaiting_payment(): void
    {
        [$order, $user] = $this->kitOrder(13.95);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => '8404890',
            'amount' => 13.95,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
        ]);

        app(PaymentCompletionService::class)->fail($payment, [
            'paid' => 0,
            'status_text' => 'unpaid',
            'invoice_id' => 8404890,
            'total' => 13.95,
            'currency' => 'EGP',
        ]);

        $order->refresh();
        $this->assertNull($order->paid_at);
        $this->assertSame(OrderStatus::AwaitingPayment->value, $order->status);
        $this->assertSame(PaymentStatus::Failed->value, $payment->fresh()->status);
        $this->assertSame('8404890', $payment->fresh()->gateway_order_id);

        $eligibility = app(OrderPaymentEligibility::class)->forOrder($order);
        $this->assertFalse($eligibility['is_paid']);
        $this->assertTrue($eligibility['payment_required']);
        $this->assertTrue($eligibility['payment_retry_allowed']);
        $this->assertSame(PaymentStatus::Failed->value, $eligibility['latest_payment_status']);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.is_paid', false)
            ->assertJsonPath('data.payment_required', true)
            ->assertJsonPath('data.payment_retry_allowed', true)
            ->assertJsonPath('data.latest_payment_status', 'failed');
    }

    public function test_retry_creates_new_payment_not_new_order_and_preserves_failed_invoice(): void
    {
        [$order, $user] = $this->kitOrder(13.95, 'SS-6SW9AOCZ');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => '8404890',
            'amount' => 13.95,
            'currency' => 'EGP',
            'status' => PaymentStatus::Failed->value,
            'gateway_response' => ['paid' => 0, 'status_text' => 'unpaid'],
        ]);
        // Simulate prior fail() leaving pending — eligibility must still allow retry.
        $order->update(['status' => OrderStatus::Pending->value]);

        Http::fake([
            'https://fawaterak.test/api/v2/getInvoiceData/8404890' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => '8404890',
                    'paid' => 0,
                    'payLoad' => ['order_number' => 'SS-6SW9AOCZ', 'local_payment_id' => 1],
                ],
            ], 200),
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoiceId' => '8405001',
                    'url' => 'https://fawaterak.test/pay/8405001',
                    'invoiceKey' => 'key-8405001',
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/8405001' => function () {
                $payment = Payment::query()->where('gateway_order_id', null)->orWhereNull('gateway_order_id')->latest('id')->first()
                    ?? Payment::query()->latest('id')->first();

                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'invoice_id' => '8405001',
                        'paid' => 0,
                        'total' => 13.95,
                        'currency' => 'EGP',
                        'pay_load' => [
                            'order_number' => 'SS-6SW9AOCZ',
                            'local_payment_id' => $payment?->id,
                            'merchant_reference' => 'SS-6SW9AOCZ-P'.($payment?->id ?? 0),
                        ],
                    ],
                ], 200);
            },
        ]);

        Sanctum::actingAs($user);
        $beforeOrders = Order::query()->count();

        $res = $this->postJson('/api/v1/checkout/'.$order->id.'/pay')->assertOk();

        $this->assertSame($beforeOrders, Order::query()->count());
        $this->assertSame('8405001', $res->json('data.gateway_order_id'));
        $this->assertSame('https://fawaterak.test/pay/8405001', $res->json('data.payment_url'));

        $this->assertSame(2, Payment::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway_order_id' => '8404890',
            'status' => PaymentStatus::Failed->value,
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway_order_id' => '8405001',
        ]);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_retry_rejects_cross_order_invoice(): void
    {
        [$orderA] = $this->kitOrder(50.0, 'SS-OWN-A');
        [$orderB, $userB] = $this->kitOrder(13.95, 'SS-OWN-B', $orderA->user);

        Payment::query()->create([
            'order_id' => $orderA->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-FOREIGN',
            'amount' => 50,
            'currency' => 'EGP',
            'status' => PaymentStatus::Completed->value,
        ]);
        Payment::query()->create([
            'order_id' => $orderB->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-OLD-B',
            'amount' => 13.95,
            'currency' => 'EGP',
            'status' => PaymentStatus::Failed->value,
        ]);

        Http::fake([
            'https://fawaterak.test/api/v2/getInvoiceData/INV-OLD-B' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => 'INV-OLD-B',
                    'paid' => 0,
                    'payLoad' => ['order_number' => 'SS-OWN-B'],
                ],
            ], 200),
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoiceId' => 'INV-FOREIGN',
                    'url' => 'https://fawaterak.test/pay/INV-FOREIGN',
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/INV-FOREIGN' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => 'INV-FOREIGN',
                    'paid' => 1,
                    'total' => 50,
                    'currency' => 'EGP',
                    'payLoad' => ['order_number' => 'SS-OWN-A', 'local_payment_id' => 1],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/checkout/'.$orderB->id.'/pay')
            ->assertStatus(422);

        $this->assertFalse(
            Payment::query()->where('order_id', $orderB->id)->where('gateway_order_id', 'INV-FOREIGN')->exists()
        );
    }

    public function test_paid_and_cancelled_orders_cannot_retry(): void
    {
        [$paidOrder, $user] = $this->kitOrder(13.95, 'SS-PAID');
        $paidOrder->update([
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
        ]);
        Payment::query()->create([
            'order_id' => $paidOrder->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-PAID',
            'amount' => 13.95,
            'currency' => 'EGP',
            'status' => PaymentStatus::Completed->value,
        ]);

        [$cancelled, $user2] = $this->kitOrder(13.95, 'SS-CANCEL');
        $cancelled->update([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/checkout/'.$paidOrder->id.'/pay')->assertStatus(422);
        $this->assertFalse(app(OrderPaymentEligibility::class)->forOrder($paidOrder)['payment_retry_allowed']);

        Sanctum::actingAs($user2);
        $this->postJson('/api/v1/checkout/'.$cancelled->id.'/pay')->assertStatus(422);
        $this->assertFalse(app(OrderPaymentEligibility::class)->forOrder($cancelled)['payment_retry_allowed']);
    }

    public function test_other_user_cannot_initiate_payment_for_order(): void
    {
        [$order] = $this->kitOrder(13.95, 'SS-OWNER');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-X',
            'amount' => 13.95,
            'currency' => 'EGP',
            'status' => PaymentStatus::Failed->value,
        ]);
        $intruder = User::factory()->create();

        Sanctum::actingAs($intruder);
        $this->postJson('/api/v1/checkout/'.$order->id.'/pay')->assertForbidden();
        $this->getJson('/api/v1/orders/'.$order->order_number)->assertNotFound();
    }

    public function test_successful_retry_completes_once_and_creates_one_bosta_shipment(): void
    {
        [$order, $user] = $this->kitOrder(13.95, 'SS-RETRY-OK');
        $failed = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-FAILED',
            'amount' => 13.95,
            'currency' => 'EGP',
            'status' => PaymentStatus::Failed->value,
        ]);

        $invoiceLookups = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$invoiceLookups) {
            $url = $request->url();
            if (str_contains($url, 'getInvoiceData/INV-FAILED')) {
                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'invoice_id' => 'INV-FAILED',
                        'paid' => 0,
                        'payLoad' => ['order_number' => 'SS-RETRY-OK'],
                    ],
                ], 200);
            }
            if (str_contains($url, 'createInvoiceLink')) {
                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'invoiceId' => 'INV-NEW',
                        'url' => 'https://fawaterak.test/pay/INV-NEW',
                    ],
                ], 200);
            }
            if (str_contains($url, 'getInvoiceData/INV-NEW')) {
                $invoiceLookups++;
                $payment = Payment::query()->latest('id')->first();
                if ($invoiceLookups === 1) {
                    return Http::response([
                        'status' => 'success',
                        'data' => [
                            'invoice_id' => 'INV-NEW',
                            'paid' => 0,
                            'total' => 13.95,
                            'currency' => 'EGP',
                            'pay_load' => [
                                'order_number' => 'SS-RETRY-OK',
                                'local_payment_id' => $payment?->id,
                                'merchant_reference' => 'SS-RETRY-OK-P'.($payment?->id ?? 0),
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'invoice_id' => 'INV-NEW',
                        'paid' => 1,
                        'total' => 13.95,
                        'currency' => 'EGP',
                        'payLoad' => [
                            'order_number' => 'SS-RETRY-OK',
                            'local_payment_id' => $payment?->id,
                        ],
                        'invoice_transactions' => [
                            ['paidWithIt' => 1, 'refrence_id' => 'txn-ok', 'paidAmount' => 13.95],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['status' => 'error', 'message' => 'unexpected '.$url], 500);
        });

        Sanctum::actingAs($user);
        $pay = $this->postJson('/api/v1/checkout/'.$order->id.'/pay')->assertOk();
        $newPaymentId = (int) $pay->json('data.payment_id');
        $this->assertNotSame($failed->id, $newPaymentId);

        $this->app->forgetInstance(FawaterakGateway::class);
        $completed = app(FawaterakGateway::class)->handleReturn($newPaymentId);
        $this->assertSame(PaymentStatus::Completed->value, $completed->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertNotNull($order->fresh()->bostaShipment?->external_shipment_id);

        // Duplicate return is idempotent.
        app(FawaterakGateway::class)->handleReturn($newPaymentId);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Completed->value)->count());
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function kitOrder(float $total, ?string $orderNumber = null, ?User $user = null): array
    {
        $user ??= User::factory()->create();
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
            'price' => $total,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => 'Science Kit', 'ar' => 'عدة'],
        ]);

        $attrs = [
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
                'first_name' => 'Buyer',
                'last_name' => 'User',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => '12 Test St',
            ],
            'shipping_address' => [
                'first_name' => 'Buyer',
                'last_name' => 'User',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => '12 Test St',
            ],
        ];
        if ($orderNumber !== null) {
            $attrs['order_number'] = $orderNumber;
        }

        $order = Order::query()->create($attrs);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Science Kit',
            'product_sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => $total,
            'total_price' => $total,
            'metadata' => ['product_type' => ProductType::Kit->value, 'course_id' => $course->id],
        ]);

        return [$order->fresh(['items', 'user']) ?? $order, $user];
    }
}
