<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum ShippingLocationScope: string
{
    case City = 'city';
    case District = 'district';
    case Zone = 'zone';
}
