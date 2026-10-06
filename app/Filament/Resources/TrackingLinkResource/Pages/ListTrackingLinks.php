<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrackingLinkResource\Pages;

use App\Filament\Resources\TrackingLinkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTrackingLinks extends ListRecords
{
    protected static string $resource = TrackingLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
