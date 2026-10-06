<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Domain\Enums;

enum ConversionEventName: string
{
    case Purchase = 'purchase';
    case BeginCheckout = 'begin_checkout';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
