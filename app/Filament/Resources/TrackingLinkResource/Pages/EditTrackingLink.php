<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrackingLinkResource\Pages;

use App\Filament\Resources\TrackingLinkResource;
use Filament\Resources\Pages\EditRecord;

class EditTrackingLink extends EditRecord
{
    protected static string $resource = TrackingLinkResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return TrackingLinkResource::normalize($data);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
