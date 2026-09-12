<?php

declare(strict_types=1);

namespace App\Filament\Resources\CertificateTemplateResource\Pages;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Resources\CertificateTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCertificateTemplate extends EditRecord
{
    protected static string $resource = CertificateTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ImageDropzone::preserveIfEmpty($data, 'background_path', $this->record->background_path);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
