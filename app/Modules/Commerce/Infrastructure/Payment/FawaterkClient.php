<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Payment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FawaterkClient
{
    public function isConfigured(): bool
    {
        return filled(config('fawaterk.api_key'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTransaction(array $payload): array
    {
        return $this->post('/api/v3/createTransaction', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::withToken((string) config('fawaterk.api_key'))
            ->acceptJson()
            ->asJson()
            ->post(
                rtrim((string) config('fawaterk.base_url'), '/').$path,
                $payload
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Fawaterk HTTP error: '.$response->body()
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException(
                'Fawaterk returned invalid JSON.'
            );
        }

        if (($json['status'] ?? '') !== 'success') {
            throw new RuntimeException(
                is_string($json['message'] ?? null)
                    ? $json['message']
                    : 'Fawaterk request failed.'
            );
        }

        $data = $json['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException(
                'Fawaterk response missing data.'
            );
        }

        return $data;
    }
}