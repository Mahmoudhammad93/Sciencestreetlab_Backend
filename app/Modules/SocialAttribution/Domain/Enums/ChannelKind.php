<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Domain\Enums;

enum ChannelKind: string
{
    case Organic = 'organic';
    case Paid = 'paid';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
