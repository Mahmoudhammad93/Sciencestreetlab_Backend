<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomeSlideResource\Pages;

use App\Filament\Resources\HomeSlideResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHomeSlides extends ListRecords
{
    protected static string $resource = HomeSlideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
