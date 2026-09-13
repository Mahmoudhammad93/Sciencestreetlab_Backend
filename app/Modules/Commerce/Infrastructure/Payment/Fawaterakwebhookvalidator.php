<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

final class FawaterakWebhookValidator
{
    /**
     * Validates the "paid" or "failed" webhook shapes, both of which are
     * signed the same way:
     *   HMAC_SHA256("InvoiceId={id}&InvoiceKey={key}&PaymentMethod={method}", vendorKey)
     *
     * @param  array<string, mixed>  $payload
     */
    public static function isValidInvoiceWebhook(array $payload, string $vendorKey): bool
    {
        $hashKey = (string) ($payload['hashKey'] ?? '');
        if ($hashKey === '' || $vendorKey === '') {
            return false;
        }

        $queryParam = sprintf(
            'InvoiceId=%s&InvoiceKey=%s&PaymentMethod=%s',
            (string) ($payload['invoice_id'] ?? ''),
            (string) ($payload['invoice_key'] ?? ''),
            (string) ($payload['payment_method'] ?? ''),
        );

        return hash_equals(hash_hmac('sha256', $queryParam, $vendorKey), $hashKey);
    }

    /**
     * Validates the Fawry/Aman/Masary "cancel" (expired) webhook shape:
     *   HMAC_SHA256("referenceId={id}&PaymentMethod={method}", vendorKey)
     *
     * @param  array<string, mixed>  $payload
     */
    public static function isValidCancelWebhook(array $payload, string $vendorKey): bool
    {
        $hashKey = (string) ($payload['hashKey'] ?? '');
        if ($hashKey === '' || $vendorKey === '') {
            return false;
        }

        $queryParam = sprintf(
            'referenceId=%s&PaymentMethod=%s',
            (string) ($payload['referenceId'] ?? ''),
            (string) ($payload['paymentMethod'] ?? ''),
        );

        return hash_equals(hash_hmac('sha256', $queryParam, $vendorKey), $hashKey);
    }
}