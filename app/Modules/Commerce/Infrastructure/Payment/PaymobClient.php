<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PaymobClient
{
    private const BASE_URL = 'https://accept.paymob.com/api';

    public function isConfigured(): bool
    {
        return filled(config('paymob.api_key'))
            && filled(config('paymob.integration_id'))
            && filled(config('paymob.iframe_id'));
    }

    public function authenticate(): string
    {
        $response = Http::post(self::BASE_URL.'/auth/tokens', [
            'api_key' => config('paymob.api_key'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Paymob authentication failed.');
        }

        return (string) $response->json('token');
    }

    public function registerOrder(string $authToken, int $amountCents, string $merchantOrderId): int
    {
        $response = Http::post(self::BASE_URL.'/ecommerce/orders', [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => $amountCents,
            'currency' => config('paymob.currency', 'EGP'),
            'merchant_order_id' => $merchantOrderId,
            'items' => [],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Paymob order registration failed.');
        }

        return (int) $response->json('id');
    }

    /**
     * @param  array<string, mixed>  $billingData
     */
    public function paymentKey(
        string $authToken,
        int $paymobOrderId,
        int $amountCents,
        array $billingData,
    ): string {
        $response = Http::post(self::BASE_URL.'/acceptance/payment_keys', [
            'auth_token' => $authToken,
            'amount_cents' => $amountCents,
            'expiration' => 3600,
            'order_id' => $paymobOrderId,
            'billing_data' => $billingData,
            'currency' => config('paymob.currency', 'EGP'),
            'integration_id' => (int) config('paymob.integration_id'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Paymob payment key request failed.');
        }

        return (string) $response->json('token');
    }

    public function iframeUrl(string $paymentToken): string
    {
        return rtrim((string) config('paymob.iframe_url'), '/')
            .'/'.config('paymob.iframe_id')
            .'?payment_token='.urlencode($paymentToken);
    }
}
