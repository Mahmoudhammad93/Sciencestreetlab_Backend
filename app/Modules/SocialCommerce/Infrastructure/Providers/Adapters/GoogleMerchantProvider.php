<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Providers\Adapters;

use App\Modules\SocialCommerce\Domain\Contracts\SalesChannelProviderInterface;
use App\Modules\SocialCommerce\Domain\Data\SalesChannelProductData;
use App\Modules\SocialCommerce\Infrastructure\Google\GoogleMerchantApiClient;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;

/**
 * Official Google Merchant API provider (Accounts + Products + Data Sources).
 *
 * Auth: service-account JWT → OAuth access token (scope auth/content).
 * Connection test: accounts.get
 * Sync: productInputs.insert into an API primary data source
 */
final class GoogleMerchantProvider implements SalesChannelProviderInterface
{
    public function __construct(
        private readonly GoogleMerchantApiClient $api,
    ) {}

    public function platform(): string
    {
        return 'google_merchant';
    }

    public function isConfigured(): bool
    {
        // Configuration lives on the integration credentials/settings, not env client secrets.
        return true;
    }

    public function hasCredentials(SalesChannelIntegration $integration): bool
    {
        $credentials = $integration->credentials ?? [];

        return filled($credentials['client_email'] ?? null)
            && filled($credentials['private_key'] ?? null)
            && filled($integration->external_account_id);
    }

    public function testConnection(SalesChannelIntegration $integration): array
    {
        if (! $this->hasCredentials($integration)) {
            return [
                'ok' => false,
                'code' => 'provider_not_configured',
                'message_key' => 'sales_channels.errors.not_configured.message',
            ];
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $integration->credentials ?? [];
        $merchantId = (string) $integration->external_account_id;

        $account = $this->api->getAccount($credentials, $merchantId);
        if (! $account['ok']) {
            return [
                'ok' => false,
                'code' => $account['code'],
                'message_key' => 'sales_channels.errors.'.$this->messageKeyFor($account['code']),
            ];
        }

        $accountName = (string) (($account['account']['accountName'] ?? null)
            ?: ($account['account']['name'] ?? null)
            ?: 'ScienceStreetLab');

        $dataSource = $this->api->ensurePrimaryDataSource(
            $credentials,
            $merchantId,
            (string) config('sales_channels.google_merchant.data_source_display_name'),
            (string) config('sales_channels.google_merchant.primary_country', 'EG'),
        );

        if (! $dataSource['ok']) {
            return [
                'ok' => false,
                'code' => $dataSource['code'],
                'message_key' => 'sales_channels.errors.'.$this->messageKeyFor($dataSource['code']),
                'account_name' => $accountName,
            ];
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'message_key' => 'sales_channels.activity.connected',
            'account_name' => $accountName,
            'data_source_name' => $dataSource['name'] ?? null,
            'merchant_id' => $merchantId,
        ];
    }

    public function syncProduct(SalesChannelIntegration $integration, SalesChannelProductData $product): array
    {
        if (! $this->hasCredentials($integration)) {
            return [
                'ok' => false,
                'code' => 'provider_not_configured',
                'message_key' => 'sales_channels.errors.not_configured.message',
            ];
        }

        $settings = $integration->settings ?? [];
        $dataSource = (string) ($settings['data_source_name'] ?? '');
        if ($dataSource === '') {
            return [
                'ok' => false,
                'code' => 'data_source_missing',
                'message_key' => 'sales_channels.errors.not_configured.message',
            ];
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $integration->credentials ?? [];
        $merchantId = (string) $integration->external_account_id;

        $payload = $this->toProductInput($product, $settings);
        $result = $this->api->insertProductInput($credentials, $merchantId, $dataSource, $payload);

        return [
            'ok' => $result['ok'],
            'code' => $result['code'],
            'message_key' => $result['ok']
                ? 'sales_channels.activity.sync_finished'
                : 'sales_channels.errors.'.$this->messageKeyFor($result['code']),
            'external_id' => $result['external_id'] ?? $product->sku,
        ];
    }

    public function syncProducts(SalesChannelIntegration $integration, array $products): array
    {
        $synced = 0;
        $failed = 0;

        foreach ($products as $product) {
            $result = $this->syncProduct($integration, $product);
            if ($result['ok']) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $failed === 0,
            'synced' => $synced,
            'failed' => $failed,
            'code' => $failed === 0 ? 'synced' : 'partial_or_failed_sync',
            'message_key' => 'sales_channels.activity.sync_finished',
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function toProductInput(SalesChannelProductData $product, array $settings): array
    {
        $language = (string) ($settings['content_language']
            ?? config('sales_channels.google_merchant.content_language', 'en'));
        $feedLabel = (string) ($settings['feed_label']
            ?? config('sales_channels.google_merchant.feed_label', 'EG'));

        $amountMicros = (string) (int) round(((float) $product->price) * 1_000_000);
        $availability = strtoupper($product->availability) === 'OUT_OF_STOCK'
            ? 'OUT_OF_STOCK'
            : 'IN_STOCK';

        $attributes = [
            'title' => $product->title,
            'description' => $product->description !== '' ? $product->description : $product->title,
            'link' => $product->url,
            'imageLink' => $product->mainImage,
            'availability' => $availability,
            'condition' => strtoupper($product->condition) === 'USED' ? 'USED' : 'NEW',
            'price' => [
                'amountMicros' => $amountMicros,
                'currencyCode' => $product->currency,
            ],
        ];

        if (filled($product->brand)) {
            $attributes['brand'] = $product->brand;
        }

        if ($product->additionalImages !== []) {
            $attributes['additionalImageLinks'] = array_values(array_slice($product->additionalImages, 0, 10));
        }

        return [
            'offerId' => $product->sku,
            'contentLanguage' => $language,
            'feedLabel' => $feedLabel,
            'productAttributes' => $attributes,
        ];
    }

    private function messageKeyFor(string $code): string
    {
        return match ($code) {
            'merchant_access_denied' => 'merchant_access_denied.message',
            'invalid_service_account', 'token_exchange_failed' => 'invalid_credentials.message',
            'invalid_merchant_id', 'merchant_not_found' => 'invalid_merchant.message',
            'data_source_missing' => 'not_configured.message',
            default => 'generic.message',
        };
    }
}
