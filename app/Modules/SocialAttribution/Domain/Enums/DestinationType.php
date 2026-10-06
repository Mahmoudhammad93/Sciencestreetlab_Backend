<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Domain\Enums;

enum DestinationType: string
{
    case Product = 'product';
    case Course = 'course';
    case LandingPage = 'landing_page';
    case InternalPath = 'internal_path';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
