<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum CouponType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
    case FreeShipping = 'free_shipping';
}
