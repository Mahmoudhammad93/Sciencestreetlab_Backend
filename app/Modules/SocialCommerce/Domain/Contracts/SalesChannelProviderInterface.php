<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Domain\Contracts;

use App\Modules\SocialCommerce\Domain\Data\SalesChannelProductData;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelIntegration;

interface SalesChannelProviderInterface
{
    public function platform(): string;

    public function isConfigured(): bool;

    /**
     * @return array{ok: bool, code: string, message_key: string}
     */
    public function testConnection(SalesChannelIntegration $integration): array;

    /**
     * @return array{ok: bool, code: string, message_key: string, external_id?: ?string}
     */
    public function syncProduct(SalesChannelIntegration $integration, SalesChannelProductData $product): array;

    /**
     * @param  list<SalesChannelProductData>  $products
     * @return array{ok: bool, synced: int, failed: int, code: string, message_key: string}
     */
    public function syncProducts(SalesChannelIntegration $integration, array $products): array;
}
