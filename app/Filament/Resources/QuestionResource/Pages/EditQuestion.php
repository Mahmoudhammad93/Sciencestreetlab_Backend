<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\QuestionResource;
use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class EditQuestion extends EditRecord
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $path = $this->data['interactive_html_upload'] ?? null;
        if (! $path || ! is_string($path)) {
            return;
        }

        $full = Storage::disk('local')->path($path);
        if (! is_file($full)) {
            return;
        }

        $upload = new UploadedFile($full, basename($full), 'text/html', null, true);
        app(InteractiveQuestionStorageService::class)->storeActivity($this->record, $upload);
    }
}
