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

final class FawaterakGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly FawaterakClient $client,
        private readonly PaymentCompletionService $completion,
    ) {}

    public function initiate(object $order): PaymentInitiationResult
    {
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('Expected Order model.');
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
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

        $billing = $order->billing_address ?? [];
        $user = $order->user;
        [$firstName, $lastName] = $this->splitName(
            (string) ($billing['first_name'] ?? $user->name ?? 'Customer')
            .' '.(string) ($billing['last_name'] ?? '')
        );

        $data = $this->client->createInvoiceLink([
            'cartTotal' => (string) $order->total,
            'currency' => (string) config('fawaterak.currency', $order->currency),
            'customer' => array_filter([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => (string) ($billing['email'] ?? $user->email),
                'phone' => $billing['phone'] ?? $user->phone ?? null,
                'address' => $billing['address'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'cartItems' => [
                [
                    'name' => 'Order '.$order->order_number,
                    'price' => (string) $order->total,
                    'quantity' => '1',
                ],
            ],
            'redirectionUrls' => [
                'successUrl' => $this->returnUrl($payment->id, 'success'),
                'failUrl' => $this->returnUrl($payment->id, 'fail'),
                'pendingUrl' => $this->returnUrl($payment->id, 'pending'),
                'webhookUrl' => url('/api/v1/payments/fawaterak/webhook'),
            ],
            'payLoad' => [
                'local_payment_id' => $payment->id,
                'order_number' => $order->order_number,
            ],
        ]);

        $invoiceId = (string) ($data['invoiceId'] ?? '');
        $invoiceUrl = (string) ($data['url'] ?? '');

        if ($invoiceId === '' || $invoiceUrl === '') {
            throw new RuntimeException('Fawaterak did not return an invoice url/id.');
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

    /**
     * Handles the server-to-server webhook Fawaterak posts on payment events.
     * Fawaterak's "paid" webhook does not carry our local_payment_id, so we
     * look the payment up by the invoice id we stored at initiation time.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): object
    {
        $invoiceId = (string) ($payload['invoice_id'] ?? '');
        if ($invoiceId === '') {
            throw new InvalidArgumentException('Webhook payload missing invoice_id.');
        }

        $payment = Payment::query()->with('order')
            ->where('gateway', 'fawaterak')
            ->where('gateway_order_id', $invoiceId)
            ->firstOrFail();

        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        $vendorKey = (string) config('fawaterak.vendor_key');
        if (! FawaterakWebhookValidator::isValidInvoiceWebhook($payload, $vendorKey)) {
            throw new RuntimeException('Invalid Fawaterak webhook signature.');
        }

        $status = strtolower((string) ($payload['invoice_status'] ?? ''));

        if ($status === 'paid') {
            $transactionId = (string) ($payload['referenceNumber'] ?? $invoiceId);

            return $this->completion->complete($payment, $transactionId !== '' ? $transactionId : null, $payload);
        }

        return $this->completion->fail($payment, $payload);
    }

    /**
     * Handles the browser redirect back from Fawaterak's hosted checkout.
     * The query string is only a hint — the real status is always
     * re-verified server-side via getInvoiceData().
     */
    public function handleReturn(int $localPaymentId): Payment
    {
        $payment = Payment::query()->with('order')->findOrFail($localPaymentId);

        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        if (! $this->client->isConfigured()) {
            throw new RuntimeException('Fawaterak is not configured.');
        }

        $invoiceId = (string) ($payment->gateway_order_id ?? '');
        if ($invoiceId === '' || str_starts_with($invoiceId, 'mock_')) {
            throw new RuntimeException('Payment has no Fawaterak invoice id.');
        }

        $data = $this->client->getInvoiceData($invoiceId);

        if ((int) ($data['paid'] ?? 0) === 1) {
            $transactionId = $this->successfulTransactionId($data);

            return $this->completion->complete($payment, $transactionId !== '' ? $transactionId : null, $data);
        }

        return $this->completion->fail($payment, $data);
    }

    public function completeMockPayment(Payment $payment): Payment
    {
        return $this->completion->complete($payment);
    }

    public function refund(object $payment, float $amount): RefundResult
    {
        return new RefundResult(false, null, 'Fawaterak refunds not yet implemented.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successfulTransactionId(array $data): string
    {
        $transactions = $data['invoice_transactions'] ?? [];
        if (! is_array($transactions)) {
            return '';
        }

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            if ((int) ($transaction['paidWithIt'] ?? 0) === 1) {
                $refId = (string) ($transaction['refrence_id'] ?? '');

                return $refId !== '' && $refId !== '-' ? $refId : (string) ($data['invoice_id'] ?? '');
            }
        }

        return (string) ($data['invoice_id'] ?? '');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];

        return [
            $parts[0] ?? 'Customer',
            $parts[1] ?? '-',
        ];
    }

    private function returnUrl(int $localPaymentId, string $stage): string
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url', 'http://localhost:5173'), '/');

        return $frontend.'/checkout/payment-return?local_payment_id='.$localPaymentId.'&stage='.$stage;
    }
}