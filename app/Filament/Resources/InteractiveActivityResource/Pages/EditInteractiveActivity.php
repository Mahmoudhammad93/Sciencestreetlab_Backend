<?php

declare(strict_types=1);

namespace App\Filament\Resources\InteractiveActivityResource\Pages;

use App\Filament\Resources\InteractiveActivityResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInteractiveActivity extends EditRecord
{
    protected static string $resource = InteractiveActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        InteractiveActivityResource::handlePackageUpload($this->record, $this->data['package_zip'] ?? null);
        InteractiveActivityResource::handleHtmlUpload($this->record, $this->data['package_html'] ?? null);
    }
}
