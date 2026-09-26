<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Providers\Adapters;

final class YouTubeShoppingProvider extends BlockedProviderAdapter
{
    public function platform(): string
    {
        return 'youtube_shopping';
    }
}
