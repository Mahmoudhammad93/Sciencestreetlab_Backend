<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\CourseResource\RelationManagers\LessonsRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ImageDropzone::preserveIfEmpty($data, 'image_url', $this->record->image_url);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('previewCourse')
                ->label('Preview course')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => LessonsRelationManager::coursePreviewUrl($this->getRecord()))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }
}
