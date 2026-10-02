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
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class FawaterakInvoiceOwnershipTest extends TestCase
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

    public function test_fawaterak_invoice_cannot_be_attached_across_orders(): void
    {
        [$orderA] = $this->kitOrderPair('SS-ORDER-A', 50.0);
        [$orderB] = $this->kitOrderPair('SS-ORDER-B', 27.9, $orderA->user);

        Payment::query()->create([
            'order_id' => $orderA->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => '8399151',
            'amount' => 50,
            'currency' => 'EGP',
            'status' => PaymentStatus::Completed->value,
        ]);

        Http::fake([
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoiceId' => '8399151',
                    'url' => 'https://fawaterak.test/pay/8399151',
                    'invoiceKey' => 'key-foreign',
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/8399151' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => '8399151',
                    'paid' => 0,
                    'url' => 'https://fawaterak.test/pay/8399151',
                    'payLoad' => ['order_number' => 'SS-ORDER-A'],
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/*' => Http::response([
                'status' => 'success',
                'data' => ['invoice_id' => '8399151', 'paid' => 0, 'url' => 'https://fawaterak.test/pay/8399151'],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already associated with a different order');

        try {
            app(FawaterakGateway::class)->initiate($orderB);
        } finally {
            $this->assertFalse(
                Payment::query()
                    ->where('order_id', $orderB->id)
                    ->where('gateway_order_id', '8399151')
                    ->exists(),
                'Foreign invoice must not be stored on order B'
            );
        }
    }

    public function test_paid_invoice_for_order_a_cannot_complete_order_b(): void
    {
        [$orderA] = $this->kitOrderPair('SS-OWN-A', 50.0);
        [$orderB] = $this->kitOrderPair('SS-OWN-B', 27.9, $orderA->user);

        $paymentA = Payment::query()->create([
            'order_id' => $orderA->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-PAID-A',
            'amount' => 50,
            'currency' => 'EGP',
            'status' => PaymentStatus::Completed->value,
        ]);

        $paymentB = Payment::query()->create([
            'order_id' => $orderB->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-PAID-A',
            'amount' => 27.9,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
        ]);

        $payload = $this->signedPaidWebhook('INV-PAID-A', 50.0, 'EGP', [
            'payLoad' => ['order_number' => 'SS-OWN-A', 'local_payment_id' => $paymentA->id],
        ]);

        $resolved = app(FawaterakGateway::class)->handleCallback($payload);
        $this->assertSame($paymentA->id, $resolved->id);
        $this->assertNull($orderB->fresh()->paid_at);
        $this->assertSame(PaymentStatus::Processing->value, $paymentB->fresh()->status);

        $this->expectException(RuntimeException::class);
        app(FawaterakGateway::class)->assertInvoiceExclusiveToOrder('INV-PAID-A', (int) $orderB->id, (int) $paymentB->id);
    }

    public function test_pay_retry_on_order_b_never_returns_order_a_invoice(): void
    {
        [$orderA] = $this->kitOrderPair('SS-RETRY-A', 50.0);
        [$orderB] = $this->kitOrderPair('SS-RETRY-B', 27.9, $orderA->user);

        Payment::query()->create([
            'order_id' => $orderA->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-A-ONLY',
            'amount' => 50,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
            'gateway_response' => ['url' => 'https://fawaterak.test/pay/INV-A-ONLY'],
        ]);

        Http::fake([
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoiceId' => 'INV-A-ONLY',
                    'url' => 'https://fawaterak.test/pay/INV-A-ONLY',
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => 'INV-A-ONLY',
                    'paid' => 0,
                    'url' => 'https://fawaterak.test/pay/INV-A-ONLY',
                    'payLoad' => ['order_number' => 'SS-RETRY-A'],
                ],
            ], 200),
        ]);

        try {
            app(FawaterakGateway::class)->initiate($orderB);
            $this->fail('Expected cross-order invoice to be rejected');
        } catch (RuntimeException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'different order')
                || str_contains($e->getMessage(), 'order_number does not match')
                || str_contains($e->getMessage(), 'merchant_reference')
            );
        }

        $rejected = Payment::query()->where('order_id', $orderB->id)->latest('id')->first();
        $this->assertNotNull($rejected);
        $this->assertSame(PaymentStatus::Failed->value, $rejected->status);
        $this->assertNull($rejected->gateway_order_id);
        $this->assertSame(
            'cross_order_or_invalid_invoice_rejected',
            data_get($rejected->gateway_response, 'error')
        );
    }

    public function test_duplicate_pay_now_reuses_same_order_unpaid_invoice_idempotently(): void
    {
        [$order] = $this->kitOrderPair('SS-SAME-1', 27.9);

        $existing = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-SAME-1',
            'amount' => 27.9,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
            'gateway_response' => ['url' => 'https://fawaterak.test/pay/INV-SAME-1'],
        ]);

        Http::fake([
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoiceId' => 'INV-SHOULD-NOT',
                    'url' => 'https://fawaterak.test/pay/new',
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/INV-SAME-1' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => 'INV-SAME-1',
                    'paid' => 0,
                    'url' => 'https://fawaterak.test/pay/INV-SAME-1',
                    'payLoad' => ['order_number' => 'SS-SAME-1'],
                    'amount' => 27.9,
                    'currency' => 'EGP',
                ],
            ], 200),
        ]);

        $first = app(FawaterakGateway::class)->initiate($order);
        $second = app(FawaterakGateway::class)->initiate($order);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'createInvoiceLink'));
        $this->assertSame($existing->id, $first->paymentId);
        $this->assertSame($existing->id, $second->paymentId);
        $this->assertSame('INV-SAME-1', $first->gatewayOrderId);
        $this->assertSame('INV-SAME-1', $second->gatewayOrderId);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_failed_invoice_retry_skips_contaminated_foreign_invoice_and_creates_new(): void
    {
        [$orderA] = $this->kitOrderPair('SS-FAIL-A', 50.0);
        [$orderB] = $this->kitOrderPair('SS-FAIL-B', 27.9, $orderA->user);

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
            'gateway_order_id' => 'INV-FOREIGN',
            'amount' => 27.9,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
            'gateway_response' => ['url' => 'https://fawaterak.test/pay/INV-FOREIGN'],
        ]);

        Payment::query()->create([
            'order_id' => $orderB->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-FAILED-B',
            'amount' => 27.9,
            'currency' => 'EGP',
            'status' => PaymentStatus::Failed->value,
        ]);

        Http::fake([
            'https://fawaterak.test/api/v2/getInvoiceData/INV-FOREIGN' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => 'INV-FOREIGN',
                    'paid' => 1,
                    'url' => 'https://fawaterak.test/pay/INV-FOREIGN',
                    'amount' => 50,
                    'currency' => 'EGP',
                    'payLoad' => ['order_number' => 'SS-FAIL-A'],
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/INV-FAILED-B' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => 'INV-FAILED-B',
                    'paid' => 0,
                    'payLoad' => ['order_number' => 'SS-FAIL-B'],
                ],
            ], 200),
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoiceId' => 'INV-NEW-B',
                    'url' => 'https://fawaterak.test/pay/INV-NEW-B',
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/INV-NEW-B' => function () {
                $payment = Payment::query()->latest('id')->first();

                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'invoice_id' => 'INV-NEW-B',
                        'paid' => 0,
                        'total' => 27.9,
                        'currency' => 'EGP',
                        'pay_load' => [
                            'order_number' => 'SS-FAIL-B',
                            'local_payment_id' => $payment?->id,
                            'merchant_reference' => 'SS-FAIL-B-P'.($payment?->id ?? 0),
                        ],
                    ],
                ], 200);
            },
        ]);

        $result = app(FawaterakGateway::class)->initiate($orderB);

        $this->assertSame('INV-NEW-B', $result->gatewayOrderId);
        $this->assertSame(
            'INV-NEW-B',
            Payment::query()->find($result->paymentId)?->gateway_order_id
        );
        $this->assertSame(
            1,
            Payment::query()->where('order_id', $orderB->id)->where('gateway_order_id', 'INV-FOREIGN')->count(),
            'Historical contaminated row must remain for audit'
        );
    }

    public function test_amount_currency_and_ownership_verification_still_enforced(): void
    {
        [$order] = $this->kitOrderPair('SS-VERIFY-1', 27.9);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => 'INV-V1',
            'amount' => 27.9,
            'currency' => 'EGP',
            'status' => PaymentStatus::Processing->value,
        ]);

        $gateway = app(FawaterakGateway::class);

        try {
            $gateway->assertAmountAndCurrencyMatch($payment, [
                'paidAmount' => 10,
                'currency' => 'EGP',
            ]);
            $this->fail('Expected amount mismatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('amount', $e->getMessage());
        }

        try {
            $gateway->assertAmountAndCurrencyMatch($payment, [
                'paidAmount' => 27.9,
                'currency' => 'USD',
            ]);
            $this->fail('Expected currency mismatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('currency', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ownership mismatch');
        $gateway->assertProviderPayloadBelongsToOrder(
            ['payLoad' => ['order_number' => 'SS-OTHER']],
            $order,
            $payment
        );
    }

    public function test_sub_minimum_amount_rejects_pay_without_creating_invoice(): void
    {
        config(['fawaterak.min_amount' => 5.01]);

        [$order] = $this->kitOrderPair('SS-MINAMT', 2.79);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('below the Fawaterak minimum');

        try {
            app(FawaterakGateway::class)->initiate($order);
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());
        }
    }

    public function test_create_invoice_http_failure_marks_payment_failed_and_returns_422_via_pay(): void
    {
        [$order, $user] = $this->kitOrderPair('SS-HTTPFAIL', 27.9);

        Http::fake([
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'error',
                'message' => ['cartTotal' => ['Amount must be bigger than 5 EGP']],
            ], 422),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/checkout/'.$order->id.'/pay')
            ->assertStatus(422);

        $payment = Payment::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Failed->value, $payment->status);
        $this->assertNull($payment->gateway_order_id);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_create_invoice_request_sends_unique_merchant_reference_and_customer_unique_id(): void
    {
        [$orderA] = $this->kitOrderPair('SS-REF-A', 13.95);
        [$orderB] = $this->kitOrderPair('SS-REF-B', 13.95, $orderA->user);

        $seen = [];

        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$seen, $orderA, $orderB) {
            if (str_contains($request->url(), 'createInvoiceLink')) {
                $body = $request->data();
                $orderNumber = (string) data_get($body, 'payLoad.order_number');
                $paymentId = (int) data_get($body, 'payLoad.local_payment_id');
                $merchantReference = (string) data_get($body, 'payLoad.merchant_reference');
                $customerUniqueId = (string) data_get($body, 'customer.customer_unique_id');
                $email = (string) data_get($body, 'customer.email');
                $invoiceId = $orderNumber === $orderA->order_number ? 'INV-REF-A' : 'INV-REF-B';

                $seen[$orderNumber] = [
                    'merchant_reference' => $merchantReference,
                    'customer_unique_id' => $customerUniqueId,
                    'email' => $email,
                    'payment_id' => $paymentId,
                    'cart_name' => (string) data_get($body, 'cartItems.0.name'),
                ];

                $this->assertSame($orderNumber.'-P'.$paymentId, $merchantReference);
                $this->assertSame($merchantReference, $customerUniqueId);
                $this->assertStringContainsString('+'.$merchantReference.'@', $email);

                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'invoiceId' => $invoiceId,
                        'url' => 'https://fawaterak.test/pay/'.$invoiceId,
                        'invoiceKey' => 'key-'.$invoiceId,
                    ],
                ], 200);
            }

            if (preg_match('#getInvoiceData/(INV-REF-[AB])#', $request->url(), $m)) {
                $invoiceId = $m[1];
                $orderNumber = $invoiceId === 'INV-REF-A' ? $orderA->order_number : $orderB->order_number;
                $meta = $seen[$orderNumber] ?? null;

                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'invoice_id' => $invoiceId,
                        'paid' => 0,
                        'total' => 13.95,
                        'currency' => 'EGP',
                        'pay_load' => [
                            'order_number' => $orderNumber,
                            'local_payment_id' => $meta['payment_id'] ?? null,
                            'merchant_reference' => $meta['merchant_reference'] ?? null,
                        ],
                    ],
                ], 200);
            }

            return Http::response(['status' => 'error', 'message' => 'unexpected'], 500);
        });

        $resultA = app(FawaterakGateway::class)->initiate($orderA);
        $resultB = app(FawaterakGateway::class)->initiate($orderB);

        $this->assertSame('INV-REF-A', $resultA->gatewayOrderId);
        $this->assertSame('INV-REF-B', $resultB->gatewayOrderId);
        $this->assertNotSame($seen[$orderA->order_number]['merchant_reference'], $seen[$orderB->order_number]['merchant_reference']);
        $this->assertNotSame($seen[$orderA->order_number]['email'], $seen[$orderB->order_number]['email']);
        $this->assertSame(PaymentStatus::Processing->value, Payment::query()->find($resultA->paymentId)?->status);
        $this->assertSame(PaymentStatus::Processing->value, Payment::query()->find($resultB->paymentId)?->status);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_provider_reused_invoice_with_foreign_payload_marks_attempt_failed_not_processing(): void
    {
        [$orderA] = $this->kitOrderPair('SS-DEDUP-A', 13.95);
        [$orderB] = $this->kitOrderPair('SS-DEDUP-B', 13.95, $orderA->user);

        Payment::query()->create([
            'order_id' => $orderA->id,
            'gateway' => 'fawaterak',
            'gateway_order_id' => '8404892',
            'amount' => 13.95,
            'currency' => 'EGP',
            'status' => PaymentStatus::Failed->value,
        ]);

        Http::fake([
            'https://fawaterak.test/api/v2/createInvoiceLink' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoiceId' => '8404892',
                    'url' => 'https://fawaterak.test/pay/8404892',
                ],
            ], 200),
            'https://fawaterak.test/api/v2/getInvoiceData/8404892' => Http::response([
                'status' => 'success',
                'data' => [
                    'invoice_id' => '8404892',
                    'paid' => 0,
                    'total' => 13.95,
                    'currency' => 'EGP',
                    'pay_load' => [
                        'order_number' => 'SS-DEDUP-A',
                        'local_payment_id' => 1,
                        'merchant_reference' => 'SS-DEDUP-A-P1',
                    ],
                ],
            ], 200),
        ]);

        try {
            app(FawaterakGateway::class)->initiate($orderB);
            $this->fail('Expected foreign reused invoice rejection');
        } catch (RuntimeException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'different order')
                || str_contains($e->getMessage(), 'order_number does not match')
                || str_contains($e->getMessage(), 'merchant_reference')
                || str_contains($e->getMessage(), 'local_payment_id')
            );
        }

        $attempt = Payment::query()->where('order_id', $orderB->id)->latest('id')->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentStatus::Failed->value, $attempt->status);
        $this->assertNull($attempt->gateway_order_id);
        $this->assertSame('cross_order_or_invalid_invoice_rejected', data_get($attempt->gateway_response, 'error'));
        $this->assertSame('8404892', (string) data_get($attempt->gateway_response, 'rejected_invoice_id'));
        $this->assertNull($orderB->fresh()->paid_at);
        $this->assertTrue(app(\App\Modules\Commerce\Application\Support\OrderPaymentEligibility::class)->forOrder($orderB->fresh())['payment_retry_allowed']);
        $this->assertDatabaseCount('shipments', 0);
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function kitOrderPair(string $orderNumber, float $total, ?User $user = null): array
    {
        $user ??= User::factory()->create(['email' => 'buyer-'.uniqid().'@example.com', 'name' => 'Buyer']);
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

        $order = Order::query()->create([
            'user_id' => $user->id,
            'order_number' => $orderNumber,
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
        ]);

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

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function signedPaidWebhook(string $invoiceId, float $amount, string $currency, array $extra = []): array
    {
        $payload = array_merge([
            'invoice_id' => $invoiceId,
            'invoice_key' => 'key-'.$invoiceId,
            'payment_method' => 'card',
            'invoice_status' => 'paid',
            'referenceNumber' => 'ref-'.$invoiceId,
            'paidAmount' => $amount,
            'currency' => $currency,
        ], $extra);
        $payload['hashKey'] = hash_hmac(
            'sha256',
            sprintf('InvoiceId=%s&InvoiceKey=%s&PaymentMethod=%s', $invoiceId, $payload['invoice_key'], $payload['payment_method']),
            'vendor-secret'
        );

        return $payload;
    }
}
