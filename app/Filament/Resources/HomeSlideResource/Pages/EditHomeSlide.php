<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomeSlideResource\Pages;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Resources\HomeSlideResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHomeSlide extends EditRecord
{
    protected static string $resource = HomeSlideResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = ImageDropzone::preserveIfEmpty($data, 'image', $this->record->image);

        return HomeSlideResource::withDisplayDuration($data, $this->data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
