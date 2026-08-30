<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MyFatoorahClient
{
    public function isConfigured(): bool
    {
        return filled(config('myfatoorah.api_key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendPayment(array $payload): array
    {
        return $this->post('/v2/SendPayment', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentStatus(string $key, string $keyType = 'PaymentId'): array
    {
        return $this->post('/v2/GetPaymentStatus', [
            'Key' => $key,
            'KeyType' => $keyType,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::withToken((string) config('myfatoorah.api_key'))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('myfatoorah.base_url'), '/').$path, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('MyFatoorah HTTP error: '.$response->body());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('MyFatoorah returned invalid JSON.');
        }

        if (! ($json['IsSuccess'] ?? false)) {
            $message = is_string($json['Message'] ?? null) ? $json['Message'] : 'MyFatoorah request failed.';
            throw new RuntimeException($message);
        }

        $data = $json['Data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('MyFatoorah response missing Data.');
        }

        return $data;
    }
}
