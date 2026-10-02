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
use Illuminate\Support\Facades\Log;
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

        if (! $this->client->isConfigured()) {
            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'gateway' => 'fawaterak',
                'amount' => $order->total,
                'currency' => $order->currency,
                'status' => PaymentStatus::Pending->value,
            ]);
            $mockToken = 'mock_'.bin2hex(random_bytes(8));
            $payment->update([
                'gateway_order_id' => 'mock_'.$order->id.'_'.$payment->id,
                'gateway_response' => ['mode' => 'mock'],
            ]);

            return new PaymentInitiationResult(
                iframeUrl: url('/api/v1/payments/mock/'.$payment->id.'?token='.$mockToken),
                paymentId: $payment->id,
                gatewayOrderId: (string) $payment->gateway_order_id,
            );
        }

        $this->assertAmountMeetsProviderMinimum($order);

        $reusable = $this->findReusableSameOrderPayment($order);
        if ($reusable !== null) {
            $invoiceId = (string) $reusable->gateway_order_id;
            $url = (string) data_get($reusable->gateway_response, 'url', '');
            if ($url === '') {
                try {
                    $data = $this->client->getInvoiceData($invoiceId);
                    $url = (string) ($data['url'] ?? $data['invoice_url'] ?? '');
                } catch (RuntimeException) {
                    $url = '';
                }
            }

            if ($url !== '') {
                return new PaymentInitiationResult(
                    iframeUrl: $url,
                    paymentId: $reusable->id,
                    gatewayOrderId: $invoiceId,
                );
            }
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => PaymentStatus::Pending->value,
        ]);

        $billing = $order->billing_address ?? [];
        $user = $order->user;
        [$firstName, $lastName] = $this->splitName(
            (string) ($billing['first_name'] ?? $user?->name ?? 'Customer')
            .' '.(string) ($billing['last_name'] ?? '')
        );

        try {
            $data = $this->client->createInvoiceLink([
                'cartTotal' => (string) $order->total,
                'currency' => (string) config('fawaterak.currency', $order->currency),
                'customer' => array_filter([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => (string) ($billing['email'] ?? $user?->email ?? ''),
                    'phone' => $billing['phone'] ?? $user?->phone ?? null,
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
        } catch (RuntimeException $e) {
            $payment->update([
                'status' => PaymentStatus::Failed->value,
                'gateway_response' => [
                    'error' => 'create_invoice_failed',
                    'message' => $e->getMessage(),
                ],
            ]);

            throw $e;
        }

        $invoiceId = (string) ($data['invoiceId'] ?? '');
        $invoiceUrl = (string) ($data['url'] ?? '');

        if ($invoiceId === '' || $invoiceUrl === '') {
            $payment->update([
                'status' => PaymentStatus::Failed->value,
                'gateway_response' => ['error' => 'missing_invoice_id_or_url', 'raw' => $data],
            ]);

            throw new RuntimeException('Fawaterak did not return an invoice url/id.');
        }

        try {
            $this->assertInvoiceExclusiveToOrder($invoiceId, (int) $order->id, (int) $payment->id);
            $this->assertProviderPayloadBelongsToOrder($data, $order, $payment);
        } catch (RuntimeException $e) {
            $payment->update([
                'status' => PaymentStatus::Failed->value,
                'gateway_response' => [
                    'error' => 'cross_order_or_invalid_invoice_rejected',
                    'message' => $e->getMessage(),
                    'rejected_invoice_id' => $invoiceId,
                ],
            ]);

            Log::error('Fawaterak createInvoiceLink rejected: invoice cannot be attached to this order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_id' => $payment->id,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
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

        $payment = $this->resolvePaymentForInvoice($invoiceId, $payload);

        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        $vendorKey = (string) config('fawaterak.vendor_key');
        if (! FawaterakWebhookValidator::isValidInvoiceWebhook($payload, $vendorKey)) {
            throw new RuntimeException('Invalid Fawaterak webhook signature.');
        }

        $this->assertOwnsInvoice($payment, $invoiceId);

        $status = strtolower((string) ($payload['invoice_status'] ?? ''));

        if ($status === 'paid') {
            $authoritative = $payload;
            if ($this->client->isConfigured()) {
                try {
                    $authoritative = array_merge($payload, $this->client->getInvoiceData($invoiceId));
                } catch (RuntimeException $e) {
                    Log::warning('Fawaterak webhook re-query failed; using signed webhook payload', [
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoiceId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->assertProviderPayloadBelongsToOrder($authoritative, $payment->order, $payment);
            $this->assertAmountAndCurrencyMatch($payment, $authoritative);

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

        $this->assertOwnsInvoice($payment, $invoiceId);
        $this->assertInvoiceExclusiveToOrder($invoiceId, (int) $payment->order_id, (int) $payment->id);

        $data = $this->client->getInvoiceData($invoiceId);

        $this->assertProviderPayloadBelongsToOrder($data, $payment->order, $payment);

        if ((int) ($data['paid'] ?? 0) === 1) {
            $this->assertAmountAndCurrencyMatch($payment, $data);
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

    private function assertAmountMeetsProviderMinimum(Order $order): void
    {
        $min = (float) config('fawaterak.min_amount', 5.01);
        $total = round((float) $order->total, 2);

        if ($total + 0.0001 < $min) {
            throw new RuntimeException(
                'Order total '.$total.' EGP is below the Fawaterak minimum of '.$min.' EGP.'
            );
        }
    }

    /**
     * Reuse an unpaid same-order invoice when still payable.
     * Never reuse an invoice that belongs to another order.
     */
    private function findReusableSameOrderPayment(Order $order): ?Payment
    {
        $candidates = Payment::query()
            ->where('order_id', $order->id)
            ->where('gateway', 'fawaterak')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
            ->whereNotNull('gateway_order_id')
            ->where('gateway_order_id', '!=', '')
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            $invoiceId = (string) $candidate->gateway_order_id;
            if ($invoiceId === '' || str_starts_with($invoiceId, 'mock_')) {
                continue;
            }

            try {
                $this->assertInvoiceExclusiveToOrder($invoiceId, (int) $order->id, (int) $candidate->id);
            } catch (RuntimeException) {
                // Historical cross-order contamination: do not reuse; leave row for audit.
                continue;
            }

            try {
                $data = $this->client->getInvoiceData($invoiceId);
            } catch (RuntimeException) {
                continue;
            }

            try {
                $this->assertProviderPayloadBelongsToOrder($data, $order, $candidate);
            } catch (RuntimeException) {
                continue;
            }

            if ((int) ($data['paid'] ?? 0) === 1) {
                // Already paid for this order — complete via normal path and surface URL unused.
                $this->assertAmountAndCurrencyMatch($candidate, $data);
                $this->completion->complete(
                    $candidate,
                    $this->successfulTransactionId($data) ?: $invoiceId,
                    $data
                );

                continue;
            }

            // Unpaid / failed-attempt invoice still owned by this order → safe reuse.
            return $candidate;
        }

        return null;
    }

    /**
     * Resolve the local payment for a provider invoice without crossing orders.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolvePaymentForInvoice(string $invoiceId, array $payload): Payment
    {
        $matches = Payment::query()
            ->with('order')
            ->where('gateway', 'fawaterak')
            ->where('gateway_order_id', $invoiceId)
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            throw new InvalidArgumentException('Unknown Fawaterak invoice.');
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $payloadOrderNumber = $this->extractOrderNumberFromProviderData($payload);
        if ($payloadOrderNumber !== null) {
            $byOrder = $matches->first(
                fn (Payment $p) => $p->order && (string) $p->order->order_number === $payloadOrderNumber
            );
            if ($byOrder !== null) {
                return $byOrder;
            }
        }

        $completed = $matches->first(fn (Payment $p) => $p->status === PaymentStatus::Completed->value);
        if ($completed !== null) {
            return $completed;
        }

        Log::warning('Ambiguous Fawaterak invoice matched multiple local payments', [
            'invoice_id' => $invoiceId,
            'payment_ids' => $matches->pluck('id')->all(),
            'order_ids' => $matches->pluck('order_id')->all(),
        ]);

        throw new RuntimeException('Fawaterak invoice is associated with multiple local payments/orders.');
    }

    /**
     * One Fawaterak invoice may belong to at most one local order.
     */
    public function assertInvoiceExclusiveToOrder(string $invoiceId, int $orderId, ?int $exceptPaymentId = null): void
    {
        $query = Payment::query()
            ->where('gateway', 'fawaterak')
            ->where('gateway_order_id', $invoiceId)
            ->where('order_id', '!=', $orderId);

        if ($exceptPaymentId !== null) {
            $query->where('id', '!=', $exceptPaymentId);
        }

        $foreign = $query->first();
        if ($foreign !== null) {
            throw new RuntimeException(
                'Fawaterak invoice '.$invoiceId.' is already associated with a different order.'
            );
        }
    }

    /**
     * Verify invoice/payment reference ownership before completing.
     */
    private function assertOwnsInvoice(Payment $payment, string $invoiceId): void
    {
        $payment->loadMissing('order');

        if ($payment->gateway !== 'fawaterak') {
            throw new RuntimeException('Payment gateway ownership mismatch.');
        }

        if ((string) $payment->gateway_order_id !== $invoiceId) {
            throw new RuntimeException('Fawaterak invoice reference does not match local payment.');
        }

        if ($payment->order === null) {
            throw new RuntimeException('Payment has no owning order.');
        }

        $this->assertInvoiceExclusiveToOrder($invoiceId, (int) $payment->order_id, (int) $payment->id);
    }

    /**
     * When provider payload carries order/payment identity, it must match local rows.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertProviderPayloadBelongsToOrder(array $data, ?Order $order, Payment $payment): void
    {
        if ($order === null) {
            throw new RuntimeException('Payment has no owning order.');
        }

        $payloadOrderNumber = $this->extractOrderNumberFromProviderData($data);
        if ($payloadOrderNumber !== null && $payloadOrderNumber !== (string) $order->order_number) {
            throw new RuntimeException(
                'Fawaterak invoice order_number does not match local order (ownership mismatch).'
            );
        }

        $payloadPaymentId = $this->extractLocalPaymentIdFromProviderData($data);
        if ($payloadPaymentId !== null && $payloadPaymentId !== (int) $payment->id) {
            // Allow historical invoices created for an earlier same-order payment only when
            // order_number matches and no foreign-order collision exists.
            $owner = Payment::query()->find($payloadPaymentId);
            if ($owner === null || (int) $owner->order_id !== (int) $order->id) {
                throw new RuntimeException(
                    'Fawaterak invoice local_payment_id does not belong to this order.'
                );
            }
        }
    }

    /**
     * Do not complete payment on amount or currency mismatch.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertAmountAndCurrencyMatch(Payment $payment, array $data): void
    {
        $payment->loadMissing('order');
        $order = $payment->order;
        if ($order === null) {
            throw new RuntimeException('Payment has no owning order for amount verification.');
        }

        $expectedAmount = round((float) $payment->amount, 2);
        $expectedOrderAmount = round((float) $order->total, 2);
        if (abs($expectedAmount - $expectedOrderAmount) > 0.009) {
            throw new RuntimeException('Local payment amount does not match order total.');
        }

        $remoteAmount = $this->extractAmount($data);
        if ($remoteAmount === null) {
            throw new RuntimeException('Fawaterak payload missing amount for verification.');
        }

        if (abs(round($remoteAmount, 2) - $expectedAmount) > 0.009) {
            Log::warning('Fawaterak amount mismatch — payment NOT completed', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'expected' => $expectedAmount,
                'remote' => $remoteAmount,
            ]);

            throw new RuntimeException('Fawaterak amount does not match local payment.');
        }

        $expectedCurrency = strtoupper(trim((string) ($payment->currency ?: $order->currency ?: 'EGP')));
        $remoteCurrency = $this->extractCurrency($data);
        if ($remoteCurrency === null || $remoteCurrency === '') {
            throw new RuntimeException('Fawaterak payload missing currency for verification.');
        }

        if (strtoupper($remoteCurrency) !== $expectedCurrency) {
            Log::warning('Fawaterak currency mismatch — payment NOT completed', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'expected' => $expectedCurrency,
                'remote' => $remoteCurrency,
            ]);

            throw new RuntimeException('Fawaterak currency does not match local payment.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractOrderNumberFromProviderData(array $data): ?string
    {
        $payload = $data['pay_load'] ?? $data['payLoad'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (is_array($payload) && isset($payload['order_number']) && is_scalar($payload['order_number'])) {
            $value = trim((string) $payload['order_number']);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractLocalPaymentIdFromProviderData(array $data): ?int
    {
        $payload = $data['pay_load'] ?? $data['payLoad'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (is_array($payload) && isset($payload['local_payment_id']) && is_numeric($payload['local_payment_id'])) {
            return (int) $payload['local_payment_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractAmount(array $data): ?float
    {
        foreach ([
            'paidAmount',
            'paid_amount',
            'amount',
            'total',
            'cartTotal',
            'invoice_value',
            'invoiceValue',
            'InvoiceValue',
            'total_paid',
            'original_amount_egp',
        ] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
            if (isset($data[$key]) && is_string($data[$key])) {
                if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $data[$key], $m)) {
                    return (float) $m[1];
                }
            }
        }

        foreach (['invoice', 'data'] as $nest) {
            if (isset($data[$nest]) && is_array($data[$nest])) {
                $nested = $this->extractAmount($data[$nest]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        $transactions = $data['invoice_transactions'] ?? null;
        if (is_array($transactions)) {
            foreach ($transactions as $transaction) {
                if (! is_array($transaction)) {
                    continue;
                }
                if ((int) ($transaction['paidWithIt'] ?? 0) === 1) {
                    foreach (['paidAmount', 'amount', 'value', 'transactionAmount'] as $key) {
                        if (isset($transaction[$key]) && is_numeric($transaction[$key])) {
                            return (float) $transaction[$key];
                        }
                        if (isset($transaction[$key]) && is_string($transaction[$key])
                            && preg_match('/([0-9]+(?:\.[0-9]+)?)/', $transaction[$key], $m)) {
                            return (float) $m[1];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractCurrency(array $data): ?string
    {
        foreach (['currency', 'Currency', 'currencyCode', 'currency_code', 'transactionCurrency'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return strtoupper(trim((string) $data[$key]));
            }
        }

        foreach (['invoice', 'data'] as $nest) {
            if (isset($data[$nest]) && is_array($data[$nest])) {
                $nested = $this->extractCurrency($data[$nest]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
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

        return $frontend.'/checkout/payment-return?'.http_build_query([
            'local_payment_id' => $localPaymentId,
            'gateway' => 'fawaterak',
            'stage' => $stage,
        ]);
    }
}
