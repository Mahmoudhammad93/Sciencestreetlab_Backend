<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialContentResource\Pages;

use App\Filament\Resources\SocialContentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSocialContent extends CreateRecord
{
    protected static string $resource = SocialContentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = SocialContentResource::normalizeDestination($data);
        $data['created_by'] = Auth::id();

        return $data;
    }
}
