<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialContentResource\Pages;

use App\Filament\Resources\SocialContentResource;
use Filament\Resources\Pages\EditRecord;

class EditSocialContent extends EditRecord
{
    protected static string $resource = SocialContentResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SocialContentResource::normalizeDestination($data);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
