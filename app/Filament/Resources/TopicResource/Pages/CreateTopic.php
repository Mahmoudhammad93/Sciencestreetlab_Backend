<?php
// app/Filament/Resources/TopicResource/Pages/CreateTopic.php

declare(strict_types=1);

namespace App\Filament\Resources\TopicResource\Pages;

use App\Filament\Resources\TopicResource;
use App\Filament\Resources\TopicResource\Concerns\HasBunnyUpload;
use App\Filament\Resources\TopicResource\Concerns\HasInteractiveHtmlUpload;
use Filament\Resources\Pages\CreateRecord;

class CreateTopic extends CreateRecord
{
    use HasBunnyUpload;
    use HasInteractiveHtmlUpload;

    protected static string $resource = TopicResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->applyBunnyVideoData($data);
    }

    protected function afterCreate(): void
    {
        $this->syncInteractivePackage($this->record->fresh(), $this->data);
    }
}
