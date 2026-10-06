<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Domain\Enums;

enum AttributionPlatform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case GoogleAds = 'google_ads';
    case Organic = 'organic';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
            self::GoogleAds => 'Google Ads',
            self::Organic => 'Organic',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
