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

final class MyFatoorahGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly MyFatoorahClient $client,
        private readonly PaymentCompletionService $completion,
    ) {}

    public function initiate(object $order): PaymentInitiationResult
    {
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('Expected Order model.');
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'myfatoorah',
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

        $callbackUrl = url('/api/v1/payments/myfatoorah/callback?local_payment_id='.$payment->id);
        $billing = $order->billing_address ?? [];
        $user = $order->user;

        $data = $this->client->sendPayment([
            'CustomerName' => (string) ($billing['first_name'] ?? $user->name ?? 'Customer'),
            'NotificationOption' => 'Lnk',
            'InvoiceValue' => (float) $order->total,
            'CustomerEmail' => (string) ($billing['email'] ?? $user->email),
            'CallBackUrl' => $callbackUrl,
            'ErrorUrl' => $callbackUrl,
            'Language' => strtolower((string) config('myfatoorah.language')) === 'en' ? 'en' : 'ar',
            'CustomerMobile' => (string) ($billing['phone'] ?? $user->phone ?? '01000000000'),
            'DisplayCurrencyIso' => (string) config('myfatoorah.currency', $order->currency),
            'CustomerReference' => $order->order_number,
        ]);

        $invoiceId = (string) ($data['InvoiceId'] ?? '');
        $invoiceUrl = (string) ($data['InvoiceURL'] ?? '');

        if ($invoiceUrl === '') {
            throw new RuntimeException('MyFatoorah did not return InvoiceURL.');
        }

        $payment->update([
            'gateway_order_id' => $invoiceId,
            'status' => PaymentStatus::Processing->value,
            'gateway_response' => $data,
        ]);

        return new PaymentInitiationResult(
            iframeUrl: $invoiceUrl,
            paymentId: $payment->id,
            gatewayOrderId: $invoiceId,
        );
    }

    public function handleCallback(array $payload): object
    {
        throw new InvalidArgumentException('Use handleReturn() for MyFatoorah browser callbacks.');
    }

    public function handleReturn(int $localPaymentId, ?string $gatewayPaymentId): Payment
    {
        $payment = Payment::query()->with('order')->findOrFail($localPaymentId);

        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        if (! $this->client->isConfigured()) {
            throw new RuntimeException('MyFatoorah is not configured.');
        }

        if ($gatewayPaymentId === null || $gatewayPaymentId === '') {
            return $this->completion->fail($payment, ['reason' => 'missing_payment_id']);
        }

        $status = $this->client->getPaymentStatus($gatewayPaymentId);
        $invoiceStatus = (string) ($status['InvoiceStatus'] ?? '');
        $transactionId = (string) ($status['InvoiceTransactions'][0]['TransactionId'] ?? $gatewayPaymentId);

        if (strcasecmp($invoiceStatus, 'Paid') === 0) {
            return $this->completion->complete($payment, $transactionId, $status);
        }

        return $this->completion->fail($payment, $status);
    }

    public function completeMockPayment(Payment $payment): Payment
    {
        return $this->completion->complete($payment);
    }

    public function refund(object $payment, float $amount): RefundResult
    {
        return new RefundResult(false, null, 'MyFatoorah refunds not yet implemented.');
    }
}
