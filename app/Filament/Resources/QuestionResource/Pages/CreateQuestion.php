<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\QuestionResource;
use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class CreateQuestion extends CreateRecord
{
    protected static string $resource = QuestionResource::class;

    protected function afterCreate(): void
    {
        $this->storeInteractiveUpload();
    }

    private function storeInteractiveUpload(): void
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
