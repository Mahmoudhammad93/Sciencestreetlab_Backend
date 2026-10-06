<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Domain\Enums;

enum CampaignStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
