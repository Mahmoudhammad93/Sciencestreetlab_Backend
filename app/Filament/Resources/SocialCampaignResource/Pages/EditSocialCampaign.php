<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialCampaignResource\Pages;

use App\Filament\Resources\SocialCampaignResource;
use Filament\Resources\Pages\EditRecord;

class EditSocialCampaign extends EditRecord
{
    protected static string $resource = SocialCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
