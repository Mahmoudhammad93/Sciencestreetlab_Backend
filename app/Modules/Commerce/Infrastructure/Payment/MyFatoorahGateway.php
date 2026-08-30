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

        $callbackUrl = $this->returnUrl($payment->id);
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
            'CustomerMobile' => MyFatoorahPhoneNormalizer::normalize($billing['phone'] ?? $user->phone ?? null),
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

        $status = $this->resolvePaymentStatus($payment, $gatewayPaymentId);

        if ($status === null) {
            return $this->completion->fail($payment, ['reason' => 'status_unavailable']);
        }

        if (MyFatoorahReturnHandler::isInvoicePaid($status)) {
            $transactionId = MyFatoorahReturnHandler::successfulTransactionId($status, $gatewayPaymentId);

            return $this->completion->complete(
                $payment,
                $transactionId !== '' ? $transactionId : null,
                $status,
            );
        }

        return $this->completion->fail($payment, $status);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePaymentStatus(Payment $payment, ?string $gatewayPaymentId): ?array
    {
        if ($gatewayPaymentId !== null && $gatewayPaymentId !== '') {
            try {
                return $this->client->getPaymentStatus($gatewayPaymentId, 'PaymentId');
            } catch (RuntimeException) {
                // Fall back to invoice lookup below.
            }
        }

        $invoiceId = (string) ($payment->gateway_order_id ?? '');
        if ($invoiceId === '' || str_starts_with($invoiceId, 'mock_')) {
            return null;
        }

        try {
            return $this->client->getPaymentStatus($invoiceId, 'InvoiceId');
        } catch (RuntimeException) {
            return null;
        }
    }

    public function completeMockPayment(Payment $payment): Payment
    {
        return $this->completion->complete($payment);
    }

    public function refund(object $payment, float $amount): RefundResult
    {
        return new RefundResult(false, null, 'MyFatoorah refunds not yet implemented.');
    }

    private function returnUrl(int $localPaymentId): string
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url', 'http://localhost:5173'), '/');

        return $frontend.'/checkout/payment-return?local_payment_id='.$localPaymentId;
    }
}
