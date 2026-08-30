<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

final class MyFatoorahReturnHandler
{
    /**
     * @param  array<string, mixed>  $query
     */
    public static function gatewayPaymentIdFromQuery(array $query): ?string
    {
        foreach (['paymentId', 'PaymentId', 'Id', 'id'] as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function isInvoicePaid(array $status): bool
    {
        return strcasecmp((string) ($status['InvoiceStatus'] ?? ''), 'Paid') === 0;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function successfulTransactionId(array $status, ?string $gatewayPaymentId = null): string
    {
        $transactions = $status['InvoiceTransactions'] ?? [];
        if (! is_array($transactions)) {
            return (string) ($gatewayPaymentId ?? '');
        }

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $txStatus = (string) ($transaction['TransactionStatus'] ?? '');
            if (strcasecmp($txStatus, 'Succss') !== 0 && strcasecmp($txStatus, 'Success') !== 0) {
                continue;
            }

            if ($gatewayPaymentId !== null && $gatewayPaymentId !== '') {
                $txPaymentId = (string) ($transaction['PaymentId'] ?? '');
                if ($txPaymentId !== '' && $txPaymentId !== $gatewayPaymentId) {
                    continue;
                }
            }

            $transactionId = (string) ($transaction['TransactionId'] ?? $transaction['PaymentId'] ?? '');

            if ($transactionId !== '') {
                return $transactionId;
            }
        }

        return (string) ($gatewayPaymentId ?? '');
    }
}
