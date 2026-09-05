<?php
// app/Filament/Resources/TopicResource/Pages/EditTopic.php

declare(strict_types=1);

namespace App\Filament\Resources\TopicResource\Pages;

use App\Filament\Resources\TopicResource;
use App\Filament\Resources\TopicResource\Concerns\HasBunnyUpload;
use App\Services\BunnyStreamService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTopic extends EditRecord
{
    use HasBunnyUpload;

    protected static string $resource = TopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refreshStatus')
                ->label('Refresh video status')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn () => filled($this->record->bunny_video_id))
                ->action(function () {
                    $service = app(BunnyStreamService::class);
                    $video = $service->getVideo($this->record->bunny_video_id);

                    // Bunny status codes: 0 Created, 1 Uploaded, 2 Processing,
                    // 3 Transcoding, 4 Finished, 5 Error
                    $statusMap = [
                        0 => 'created', 1 => 'uploaded', 2 => 'processing',
                        3 => 'transcoding', 4 => 'finished', 5 => 'error',
                    ];

                    $this->record->update([
                        'video_status' => $statusMap[$video['status']] ?? 'unknown',
                        'video_progress' => $video['encodeProgress'] ?? 0,
                    ]);

                    $this->fillForm();

                    Notification::make()
                        ->title('Status updated: '.($statusMap[$video['status']] ?? 'unknown'))
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->applyBunnyVideoData($data);
    }
}