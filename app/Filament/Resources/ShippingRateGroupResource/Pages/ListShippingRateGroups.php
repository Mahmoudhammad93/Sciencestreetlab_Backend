<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingRateGroupResource\Pages;

use App\Filament\Resources\ShippingRateGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShippingRateGroups extends ListRecords
{
    protected static string $resource = ShippingRateGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
