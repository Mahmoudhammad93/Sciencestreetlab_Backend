<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialContentResource\Pages;

use App\Filament\Resources\SocialContentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSocialContents extends ListRecords
{
    protected static string $resource = SocialContentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
