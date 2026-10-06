<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialCampaignResource\Pages;

use App\Filament\Resources\SocialCampaignResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSocialCampaign extends CreateRecord
{
    protected static string $resource = SocialCampaignResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
