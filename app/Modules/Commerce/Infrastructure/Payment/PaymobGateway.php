<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Shared\Contracts\PaymentGatewayInterface;
use App\Shared\Contracts\PaymentInitiationResult;
use App\Shared\Contracts\RefundResult;
use InvalidArgumentException;
use RuntimeException;

final class PaymobGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly PaymobClient $client,
        private readonly PaymobHmacValidator $hmacValidator,
    ) {}

    public function initiate(object $order): PaymentInitiationResult
    {
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('Expected Order model.');
        }

        $amountCents = (int) round(((float) $order->total) * 100);

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'paymob',
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => PaymentStatus::Pending->value,
        ]);

        if (! $this->client->isConfigured()) {
            $mockToken = 'mock_'.bin2hex(random_bytes(8));
            $payment->update([
                'gateway_order_id' => 'mock_'.$order->id,
                'gateway_response' => ['mode' => 'mock'],
            ]);

            return new PaymentInitiationResult(
                iframeUrl: url('/api/v1/payments/mock/'.$payment->id.'?token='.$mockToken),
                paymentId: $payment->id,
                gatewayOrderId: (string) $payment->gateway_order_id,
            );
        }

        $authToken = $this->client->authenticate();
        $paymobOrderId = $this->client->registerOrder($authToken, $amountCents, $order->order_number);
        $billing = $this->billingDataFromOrder($order);
        $paymentKey = $this->client->paymentKey($authToken, $paymobOrderId, $amountCents, $billing);

        $payment->update([
            'gateway_order_id' => (string) $paymobOrderId,
            'status' => PaymentStatus::Processing->value,
        ]);

        return new PaymentInitiationResult(
            iframeUrl: $this->client->iframeUrl($paymentKey),
            paymentId: $payment->id,
            gatewayOrderId: (string) $paymobOrderId,
        );
    }

    public function handleCallback(array $payload): object
    {
        if (! $this->hmacValidator->isValid($payload)) {
            throw new InvalidArgumentException('Invalid Paymob callback signature.');
        }

        $obj = $payload['obj'] ?? $payload;
        $transactionId = (string) ($obj['id'] ?? '');
        $gatewayOrderId = (string) (is_array($obj['order'] ?? null) ? ($obj['order']['id'] ?? '') : ($obj['order'] ?? ''));

        $payment = null;

        if ($transactionId !== '') {
            $payment = Payment::query()->where('transaction_id', $transactionId)->first();
        }

        if (! $payment && $gatewayOrderId !== '') {
            $payment = Payment::query()->where('gateway_order_id', $gatewayOrderId)->first();
        }

        if (! $payment) {
            throw new RuntimeException('Payment record not found for callback.');
        }

        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        $success = filter_var($obj['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $isVoided = filter_var($obj['is_voided'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($success && ! $isVoided) {
            $payment->update([
                'transaction_id' => $transactionId ?: $payment->transaction_id,
                'status' => PaymentStatus::Completed->value,
                'payment_method' => data_get($obj, 'source_data.type'),
                'gateway_response' => $obj,
                'paid_at' => now(),
            ]);

            $order = $payment->order;
            $order->update([
                'status' => OrderStatus::Paid->value,
                'paid_at' => now(),
            ]);

            event(new OrderPaid($order->fresh(['items'])));
        } else {
            $payment->update([
                'status' => PaymentStatus::Failed->value,
                'gateway_response' => $obj,
            ]);
            $payment->order->update(['status' => OrderStatus::Pending->value]);
        }

        return $payment->fresh();
    }

    public function refund(object $payment, float $amount): RefundResult
    {
        return new RefundResult(false, null, 'Paymob refunds not yet implemented.');
    }

    /**
     * Complete a mock payment in local/dev when Paymob is not configured.
     */
    public function completeMockPayment(Payment $payment): Payment
    {
        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        $payment->update([
            'transaction_id' => 'mock_txn_'.$payment->id,
            'status' => PaymentStatus::Completed->value,
            'paid_at' => now(),
            'gateway_response' => ['mode' => 'mock', 'completed_at' => now()->toIso8601String()],
        ]);

        $order = $payment->order;
        $order->update([
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
        ]);

        event(new OrderPaid($order->fresh(['items'])));

        return $payment->fresh();
    }

    /** @return array<string, mixed> */
    private function billingDataFromOrder(Order $order): array
    {
        $billing = $order->billing_address;
        $user = $order->user;

        return [
            'apartment' => $billing['apartment'] ?? 'NA',
            'email' => $billing['email'] ?? $user->email,
            'floor' => $billing['floor'] ?? 'NA',
            'first_name' => $billing['first_name'] ?? $user->name,
            'street' => $billing['street'] ?? 'NA',
            'building' => $billing['building'] ?? 'NA',
            'phone_number' => $billing['phone'] ?? $user->phone ?? '01000000000',
            'shipping_method' => 'PKG',
            'postal_code' => $billing['postal_code'] ?? 'NA',
            'city' => $billing['city'] ?? 'Cairo',
            'country' => $billing['country'] ?? 'EG',
            'state' => $billing['state'] ?? 'Cairo',
            'last_name' => $billing['last_name'] ?? '.',
        ];
    }
}
