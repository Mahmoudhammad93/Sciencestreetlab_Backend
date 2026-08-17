<?php

declare(strict_types=1);

namespace App\Filament\Resources\InteractiveActivityResource\Pages;

use App\Filament\Resources\InteractiveActivityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInteractiveActivities extends ListRecords
{
    protected static string $resource = InteractiveActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
