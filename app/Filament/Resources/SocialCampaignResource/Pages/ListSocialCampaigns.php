<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialCampaignResource\Pages;

use App\Filament\Resources\SocialCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSocialCampaigns extends ListRecords
{
    protected static string $resource = SocialCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
