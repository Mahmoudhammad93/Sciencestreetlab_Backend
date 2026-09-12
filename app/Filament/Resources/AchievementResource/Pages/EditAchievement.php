<?php

declare(strict_types=1);

namespace App\Filament\Resources\AchievementResource\Pages;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Resources\AchievementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAchievement extends EditRecord
{
    protected static string $resource = AchievementResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ImageDropzone::preserveIfEmpty($data, 'icon_path', $this->record->icon_path);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
