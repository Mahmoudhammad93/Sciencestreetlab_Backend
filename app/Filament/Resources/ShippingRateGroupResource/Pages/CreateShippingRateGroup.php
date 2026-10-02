<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingRateGroupResource\Pages;

use App\Filament\Resources\ShippingRateGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShippingRateGroup extends CreateRecord
{
    protected static string $resource = ShippingRateGroupResource::class;
}
