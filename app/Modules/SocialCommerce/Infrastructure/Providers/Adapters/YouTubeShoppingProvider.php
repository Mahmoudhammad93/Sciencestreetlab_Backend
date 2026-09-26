<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Providers\Adapters;

use App\Modules\SocialCommerce\Domain\Contracts\SalesChannelProviderInterface;
use App\Modules\SocialCommerce\Domain\Data\SalesChannelProductData;
use App\Modules\SocialCommerce\Domain\Enums\ConnectionStatus;
use App\Modules\SocialCommerce\Domain\Enums\SalesChannelPlatform;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;

/**
 * YouTube Shopping does not expose a direct product-publish twin of Merchant API here.
 * This provider only reports readiness dependency on Google Merchant — never invents APIs.
 */
final class YouTubeShoppingProvider implements SalesChannelProviderInterface
{
    public function platform(): string
    {
        return 'youtube_shopping';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function testConnection(SalesChannelIntegration $integration): array
    {
        $merchant = SalesChannelIntegration::query()
            ->where('platform', SalesChannelPlatform::GoogleMerchant->value)
            ->first();

        if ($merchant === null || $merchant->connection_status !== ConnectionStatus::Connected) {
            return [
                'ok' => false,
                'code' => 'merchant_required',
                'message_key' => 'sales_channels.youtube.merchant_required',
            ];
        }

        if (! (bool) config('sales_channels.youtube_shopping.eligibility_confirmed', false)) {
            return [
                'ok' => false,
                'code' => 'youtube_eligibility_unconfirmed',
                'message_key' => 'sales_channels.youtube.eligibility_unconfirmed',
            ];
        }

        if (! (bool) config('sales_channels.youtube_shopping.store_linked', false)) {
            return [
                'ok' => false,
                'code' => 'youtube_store_unlinked',
                'message_key' => 'sales_channels.youtube.store_unlinked',
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
        return [
            'ok' => false,
            'code' => 'youtube_not_direct_api',
            'message_key' => 'sales_channels.youtube.not_direct_api',
        ];
    }

    public function syncProducts(SalesChannelIntegration $integration, array $products): array
    {
        return [
            'ok' => false,
            'synced' => 0,
            'failed' => count($products),
            'code' => 'youtube_not_direct_api',
            'message_key' => 'sales_channels.youtube.not_direct_api',
        ];
    }
}
