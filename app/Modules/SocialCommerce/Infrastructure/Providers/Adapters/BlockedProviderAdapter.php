<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Providers\Adapters;

use App\Modules\SocialCommerce\Domain\Contracts\SalesChannelProviderInterface;
use App\Modules\SocialCommerce\Domain\Data\SalesChannelProductData;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;

/**
 * Safe stub until official Google Merchant credentials and API contracts are configured.
 * Does not invent OAuth scopes or Merchant endpoints.
 */
abstract class BlockedProviderAdapter implements SalesChannelProviderInterface
{
    abstract public function platform(): string;

    public function isConfigured(): bool
    {
        return (bool) config('sales_channels.'.$this->platform().'.enabled', false)
            && filled(config('sales_channels.'.$this->platform().'.client_id'))
            && filled(config('sales_channels.'.$this->platform().'.client_secret'));
    }

    public function testConnection(SalesChannelIntegration $integration): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'code' => 'provider_not_configured',
                'message_key' => 'sales_channels.errors.not_configured.message',
            ];
        }

        // Credentials present but live API contract not enabled yet.
        if (! (bool) config('sales_channels.'.$this->platform().'.api_contract_ready', false)) {
            return [
                'ok' => false,
                'code' => 'provider_not_configured',
                'message_key' => 'sales_channels.errors.not_configured.message',
            ];
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'message_key' => 'sales_channels.activity.connected',
        ];
    }

    public function syncProduct(SalesChannelIntegration $integration, SalesChannelProductData $product): array
    {
        if (! $this->isConfigured() || ! (bool) config('sales_channels.'.$this->platform().'.api_contract_ready', false)) {
            return [
                'ok' => false,
                'code' => 'provider_not_configured',
                'message_key' => 'sales_channels.errors.not_configured.message',
            ];
        }

        // Placeholder success path for future real HTTP client.
        return [
            'ok' => true,
            'code' => 'synced',
            'message_key' => 'sales_channels.activity.sync_finished',
            'external_id' => $product->sku,
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
}
