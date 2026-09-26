<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Providers\Adapters;

final class GoogleMerchantProvider extends BlockedProviderAdapter
{
    public function platform(): string
    {
        return 'google_merchant';
    }
}
