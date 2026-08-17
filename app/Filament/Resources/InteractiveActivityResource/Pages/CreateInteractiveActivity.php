<?php

declare(strict_types=1);

namespace App\Filament\Resources\InteractiveActivityResource\Pages;

use App\Filament\Resources\InteractiveActivityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInteractiveActivity extends CreateRecord
{
    protected static string $resource = InteractiveActivityResource::class;

    protected function afterCreate(): void
    {
        InteractiveActivityResource::handlePackageUpload($this->record, $this->data['package_zip'] ?? null);
        InteractiveActivityResource::handleHtmlUpload($this->record, $this->data['package_html'] ?? null);
    }
}
