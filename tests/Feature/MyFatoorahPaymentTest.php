<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MyFatoorahPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_myfatoorah_mock_payment_initiates_and_completes(): void
    {
        config(['commerce.payment_gateway' => 'myfatoorah']);
        config(['myfatoorah.api_key' => null]);

        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();

        $checkout = $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'Sara',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'shipping_address' => ['city' => 'Cairo', 'country' => 'EG'],
        ])->assertCreated();

        $orderId = $checkout->json('data.id');

        $pay = $this->postJson("/api/v1/checkout/{$orderId}/pay")
            ->assertOk()
            ->assertJsonPath('data.gateway', 'myfatoorah');

        $paymentId = $pay->json('data.payment_id');
        $this->assertStringContainsString('/payments/mock/', $pay->json('data.payment_url'));

        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'paid']);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $product->fresh()->course_id,
        ]);
    }

    public function test_myfatoorah_confirm_completes_order_when_gateway_reports_paid(): void
    {
        config(['commerce.payment_gateway' => 'myfatoorah']);
        config(['myfatoorah.api_key' => 'test-key']);
        config(['myfatoorah.base_url' => 'https://apitest.myfatoorah.com']);
        config(['sciencestreet.frontend_url' => 'http://localhost:5174']);

        Http::fake([
            'https://apitest.myfatoorah.com/v2/SendPayment' => Http::response([
                'IsSuccess' => true,
                'Data' => [
                    'InvoiceId' => 9001,
                    'InvoiceURL' => 'https://demo.myfatoorah.com/invoice/9001',
                ],
            ]),
            'https://apitest.myfatoorah.com/v2/GetPaymentStatus' => Http::response([
                'IsSuccess' => true,
                'Data' => [
                    'InvoiceStatus' => 'Paid',
                    'InvoiceTransactions' => [
                        [
                            'TransactionStatus' => 'Succss',
                            'TransactionId' => 'tx-paid-1',
                            'PaymentId' => 'gw-pay-1',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->seed();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertCreated();

        $checkout = $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'Sara',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'shipping_address' => ['city' => 'Cairo', 'country' => 'EG'],
        ])->assertCreated();

        $orderId = $checkout->json('data.id');
        $orderNumber = $checkout->json('data.order_number');

        $pay = $this->postJson("/api/v1/checkout/{$orderId}/pay")->assertOk();
        $localPaymentId = $pay->json('data.payment_id');

        $this->getJson("/api/v1/payments/myfatoorah/confirm?local_payment_id={$localPaymentId}&paymentId=gw-pay-1")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.order_number', $orderNumber);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', [
            'id' => $localPaymentId,
            'status' => 'completed',
            'transaction_id' => 'tx-paid-1',
        ]);
    }
}
