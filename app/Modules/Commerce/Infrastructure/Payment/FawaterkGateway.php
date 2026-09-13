<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Shared\Contracts\PaymentGatewayInterface;
use App\Shared\Contracts\PaymentInitiationResult;
use App\Shared\Contracts\RefundResult;
use InvalidArgumentException;
use RuntimeException;

final class FawaterkGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly FawaterkClient $client,
        private readonly PaymentCompletionService $completion,
    ) {}

    public function initiate(object $order): PaymentInitiationResult
    {
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('Expected Order model.');
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterk',
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => PaymentStatus::Pending->value,
        ]);

        if (! $this->client->isConfigured()) {
            $mockToken = 'mock_'.bin2hex(random_bytes(8));

            $payment->update([
                'gateway_order_id' => 'mock_'.$order->id,
                'gateway_response' => [
                    'mode' => 'mock',
                ],
            ]);

            return new PaymentInitiationResult(
                iframeUrl: url(
                    '/api/v1/payments/mock/'.$payment->id.'?token='.$mockToken
                ),
                paymentId: $payment->id,
                gatewayOrderId: (string) $payment->gateway_order_id,
            );
        }

        $billing = $order->billing_address ?? [];
        $user = $order->user;

        $firstName = (string) (
            $billing['first_name']
            ?? $user->name
            ?? 'Customer'
        );

        $lastName = (string) (
            $billing['last_name']
            ?? ''
        );

        $email = (string) (
            $billing['email']
            ?? $user->email
            ?? ''
        );

        $phone = (string) (
            $billing['phone']
            ?? $user->phone
            ?? ''
        );

        $payload = [
            'payment_method_id' => (int) config(
                'fawaterk.payment_method_id'
            ),

            'cartTotal' => (float) $order->total,

            'currency' => (string) $order->currency,

            'customer' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'address' => (string) (
                    $billing['address']
                    ?? ''
                ),
                'customer_number' => (string) $order->order_number,
                'customer_unique_id' => (string) $user->id,
            ],

            'cartItems' => [
                [
                    'name' => 'Order total',
                    'price' => (float) $order->total,
                    'quantity' => 1,
                ],
            ],

            'pay_load' => [
                'order_id' => (string) $order->order_number,
                'customer_reference' => (string) $order->order_number,
            ],

            'redirectionUrls' => [
                'successUrl' => $this->successUrl($payment->id),
                'failUrl' => $this->failUrl($payment->id),
                'pendingUrl' => $this->pendingUrl($payment->id),
                'backUrl' => $this->backUrl($payment->id),
                'webhookUrl' => $this->webhookUrl(),
            ],

            'sendEmail' => true,
            'sendSMS' => false,

            'redirectOption' => false,

            'authAndCapture' => 0,

            'lang' => strtolower(
                (string) config('fawaterk.language', 'en')
            ),
        ];

        $data = $this->client->createTransaction($payload);

        $intentKey = (string) (
            $data['intent_key'] ?? ''
        );

        $paymentUrl = (string) (
            $data['url'] ?? ''
        );

        if ($paymentUrl === '') {
            throw new RuntimeException(
                'Fawaterk did not return a payment URL.'
            );
        }

        $payment->update([
            'gateway_order_id' => $intentKey,
            'status' => PaymentStatus::Processing->value,
            'gateway_response' => $data,
        ]);

        return new PaymentInitiationResult(
            iframeUrl: $paymentUrl,
            paymentId: $payment->id,
            gatewayOrderId: $intentKey,
        );
    }

    public function handleCallback(array $payload): object
    {
        throw new InvalidArgumentException(
            'Use Fawaterk webhook/return handling.'
        );
    }

    public function refund(
        object $payment,
        float $amount
    ): RefundResult {
        return new RefundResult(
            false,
            null,
            'Fawaterk refunds not yet implemented.'
        );
    }

    private function successUrl(int $paymentId): string
    {
        return $this->frontendUrl(
            '/checkout/payment-return?payment_id='.$paymentId.'&status=success'
        );
    }

    private function failUrl(int $paymentId): string
    {
        return $this->frontendUrl(
            '/checkout/payment-return?payment_id='.$paymentId.'&status=failed'
        );
    }

    private function pendingUrl(int $paymentId): string
    {
        return $this->frontendUrl(
            '/checkout/payment-return?payment_id='.$paymentId.'&status=pending'
        );
    }

    private function backUrl(int $paymentId): string
    {
        return $this->frontendUrl(
            '/checkout/payment-return?payment_id='.$paymentId
        );
    }

    private function webhookUrl(): string
    {
        return rtrim(
            (string) config('sciencestreet.backend_url'),
            '/'
        ).'/api/v1/payments/fawaterk/webhook';
    }

    private function frontendUrl(string $path): string
    {
        return rtrim(
            (string) config(
                'sciencestreet.frontend_url',
                'http://localhost:5173'
            ),
            '/'
        ).$path;
    }
}