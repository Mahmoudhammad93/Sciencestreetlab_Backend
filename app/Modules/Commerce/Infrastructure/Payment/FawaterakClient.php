<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class FawaterakClient
{
    public function isConfigured(): bool
    {
        return filled(config('fawaterak.api_key'));
    }

    /**
     * Creates an invoice link (POST /api/v2/createInvoiceLink).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Decoded "data" node: url, invoiceKey, invoiceId
     */
    public function createInvoiceLink(array $payload): array
    {
        return $this->post('/api/v2/createInvoiceLink', $payload);
    }

    /**
     * Fetches full invoice/transaction data (GET /api/v2/getInvoiceData/{invoiceId}).
     *
     * @return array<string, mixed>
     */
    public function getInvoiceData(string $invoiceId): array
    {
        return $this->get('/api/v2/getInvoiceData/'.$invoiceId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::withToken((string) config('fawaterak.api_key'))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) config('fawaterak.base_url'), '/').$path, $payload);

        if (! $response->successful()) {
            Log::warning('Fawaterak POST failed', [
                'path' => $path,
                'payload' => $payload,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $this->unwrap($response->successful() ? $response->json() : null, $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = Http::withToken((string) config('fawaterak.api_key'))
            ->acceptJson()
            ->contentType('application/json')
            ->get(rtrim((string) config('fawaterak.base_url'), '/').$path);

        return $this->unwrap($response->successful() ? $response->json() : null, $response->body());
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function unwrap(?array $json, string $rawBody): array
    {
        if ($json === null) {
            throw new RuntimeException('Fawaterak HTTP error: '.$rawBody);
        }

        if (strcasecmp((string) ($json['status'] ?? ''), 'success') !== 0) {
            $message = is_string($json['message'] ?? null) ? $json['message'] : 'Fawaterak request failed.';
            throw new RuntimeException($message);
        }

        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Fawaterak response missing data.');
        }

        return $data;
    }
}
