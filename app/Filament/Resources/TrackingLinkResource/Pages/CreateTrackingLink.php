<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrackingLinkResource\Pages;

use App\Filament\Resources\TrackingLinkResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTrackingLink extends CreateRecord
{
    protected static string $resource = TrackingLinkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = TrackingLinkResource::normalize($data);
        $data['created_by'] = Auth::id();

        return $data;
    }
}
