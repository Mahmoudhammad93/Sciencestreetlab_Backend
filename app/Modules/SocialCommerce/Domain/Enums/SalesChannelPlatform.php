<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Domain\Enums;

enum SalesChannelPlatform: string
{
    case GoogleMerchant = 'google_merchant';
    case YouTubeShopping = 'youtube_shopping';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return (string) __('sales_channels.platforms.'.$this->value);
    }

    public function isImplemented(): bool
    {
        return match ($this) {
            self::GoogleMerchant, self::YouTubeShopping => true,
            default => false,
        };
    }
}
