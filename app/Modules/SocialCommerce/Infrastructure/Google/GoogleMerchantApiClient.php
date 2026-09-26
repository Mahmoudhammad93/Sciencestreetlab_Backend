<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Google;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP client for official Merchant API REST endpoints.
 *
 * @see https://developers.google.com/merchant/api/reference/rest/accounts_v1/accounts/get
 * @see https://developers.google.com/merchant/api/reference/rest/products_v1/accounts.productInputs/insert
 * @see https://developers.google.com/merchant/api/reference/rest/datasources_v1/accounts.dataSources/create
 */
final class GoogleMerchantApiClient
{
    public function __construct(
        private readonly GoogleServiceAccountTokenProvider $tokens,
    ) {}

    /**
     * @param  array<string, mixed>  $serviceAccount
     * @return array{ok: bool, code: string, account?: array<string, mixed>, http_status?: int}
     */
    public function getAccount(array $serviceAccount, string $merchantId): array
    {
        $merchantId = trim($merchantId);
        if ($merchantId === '' || ! ctype_digit($merchantId)) {
            return ['ok' => false, 'code' => 'invalid_merchant_id'];
        }

        try {
            $response = $this->authorized($serviceAccount, $merchantId)
                ->get($this->accountsBase().'/accounts/'.$merchantId);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => $e->getMessage()];
        }

        if ($response->status() === 403 || $response->status() === 401) {
            return ['ok' => false, 'code' => 'merchant_access_denied', 'http_status' => $response->status()];
        }

        if ($response->status() === 404) {
            return ['ok' => false, 'code' => 'merchant_not_found', 'http_status' => 404];
        }

        if (! $response->successful()) {
            $this->logSanitizedFailure('accounts.get', $response->status());

            return ['ok' => false, 'code' => 'merchant_api_error', 'http_status' => $response->status()];
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return ['ok' => true, 'code' => 'ok', 'account' => $json];
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     * @return array{ok: bool, code: string, name?: string, http_status?: int}
     */
    public function ensurePrimaryDataSource(array $serviceAccount, string $merchantId, string $displayName, string $country): array
    {
        $list = $this->listDataSources($serviceAccount, $merchantId);
        if (! $list['ok']) {
            return $list;
        }

        foreach ($list['data_sources'] as $source) {
            if (! is_array($source)) {
                continue;
            }
            if (($source['input'] ?? null) === 'API' && isset($source['primaryProductDataSource']) && filled($source['name'] ?? null)) {
                return [
                    'ok' => true,
                    'code' => 'ok',
                    'name' => (string) $source['name'],
                ];
            }
        }

        return $this->createPrimaryDataSource($serviceAccount, $merchantId, $displayName, $country);
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     * @return array{ok: bool, code: string, data_sources?: list<array<string, mixed>>, http_status?: int}
     */
    public function listDataSources(array $serviceAccount, string $merchantId): array
    {
        try {
            $response = $this->authorized($serviceAccount, $merchantId)
                ->get($this->datasourcesBase().'/accounts/'.$merchantId.'/dataSources');
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => $e->getMessage()];
        }

        if ($response->status() === 403 || $response->status() === 401) {
            return ['ok' => false, 'code' => 'merchant_access_denied', 'http_status' => $response->status()];
        }

        if (! $response->successful()) {
            $this->logSanitizedFailure('dataSources.list', $response->status());

            return ['ok' => false, 'code' => 'merchant_api_error', 'http_status' => $response->status()];
        }

        $sources = $response->json('dataSources') ?? [];
        if (! is_array($sources)) {
            $sources = [];
        }

        /** @var list<array<string, mixed>> $sources */
        return ['ok' => true, 'code' => 'ok', 'data_sources' => array_values($sources)];
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     * @return array{ok: bool, code: string, name?: string, http_status?: int}
     */
    public function createPrimaryDataSource(array $serviceAccount, string $merchantId, string $displayName, string $country): array
    {
        $payload = [
            'displayName' => $displayName,
            'primaryProductDataSource' => [
                'countries' => [$country],
            ],
        ];

        try {
            $response = $this->authorized($serviceAccount, $merchantId)
                ->post($this->datasourcesBase().'/accounts/'.$merchantId.'/dataSources', $payload);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => $e->getMessage()];
        }

        if ($response->status() === 403 || $response->status() === 401) {
            return ['ok' => false, 'code' => 'merchant_access_denied', 'http_status' => $response->status()];
        }

        if (! $response->successful()) {
            $this->logSanitizedFailure('dataSources.create', $response->status());

            return ['ok' => false, 'code' => 'merchant_api_error', 'http_status' => $response->status()];
        }

        $name = (string) ($response->json('name') ?? '');
        if ($name === '') {
            return ['ok' => false, 'code' => 'merchant_api_error'];
        }

        return ['ok' => true, 'code' => 'ok', 'name' => $name];
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     * @param  array<string, mixed>  $productInput
     * @return array{ok: bool, code: string, external_id?: ?string, http_status?: int}
     */
    public function insertProductInput(
        array $serviceAccount,
        string $merchantId,
        string $dataSourceName,
        array $productInput,
    ): array {
        $url = $this->productsBase().'/accounts/'.$merchantId.'/productInputs:insert';

        try {
            $response = $this->authorized($serviceAccount, $merchantId)
                ->withQueryParameters(['dataSource' => $dataSourceName])
                ->post($url, $productInput);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'code' => $e->getMessage()];
        }

        if ($response->status() === 403 || $response->status() === 401) {
            return ['ok' => false, 'code' => 'merchant_access_denied', 'http_status' => $response->status()];
        }

        if (! $response->successful()) {
            $this->logSanitizedFailure('productInputs.insert', $response->status());

            return ['ok' => false, 'code' => 'product_sync_failed', 'http_status' => $response->status()];
        }

        $product = $response->json('product');
        $offerId = $response->json('offerId');

        return [
            'ok' => true,
            'code' => 'synced',
            'external_id' => is_string($product) && $product !== ''
                ? $product
                : (is_string($offerId) ? $offerId : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     */
    private function authorized(array $serviceAccount, string $merchantId): PendingRequest
    {
        $email = (string) ($serviceAccount['client_email'] ?? 'unknown');
        $token = $this->tokens->accessToken(
            $serviceAccount,
            'google-merchant-token:'.hash('sha256', $email.'|'.$merchantId)
        );

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function accountsBase(): string
    {
        return rtrim((string) config('sales_channels.google_merchant.accounts_base_url'), '/');
    }

    private function productsBase(): string
    {
        return rtrim((string) config('sales_channels.google_merchant.products_base_url'), '/');
    }

    private function datasourcesBase(): string
    {
        return rtrim((string) config('sales_channels.google_merchant.datasources_base_url'), '/');
    }

    private function logSanitizedFailure(string $operation, int $status): void
    {
        Log::warning('Google Merchant API request failed', [
            'operation' => $operation,
            'http_status' => $status,
            // Never log response bodies — may contain account details.
        ]);
    }
}
