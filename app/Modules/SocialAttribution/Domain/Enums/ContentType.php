<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Domain\Enums;

enum ContentType: string
{
    case YoutubeVideo = 'youtube_video';
    case FacebookPost = 'facebook_post';
    case FacebookAd = 'facebook_ad';
    case InstagramPost = 'instagram_post';
    case InstagramReel = 'instagram_reel';
    case InstagramStory = 'instagram_story';
    case TiktokVideo = 'tiktok_video';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::YoutubeVideo => 'YouTube Video',
            self::FacebookPost => 'Facebook Post',
            self::FacebookAd => 'Facebook Ad',
            self::InstagramPost => 'Instagram Post',
            self::InstagramReel => 'Instagram Reel',
            self::InstagramStory => 'Instagram Story',
            self::TiktokVideo => 'TikTok Video',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
