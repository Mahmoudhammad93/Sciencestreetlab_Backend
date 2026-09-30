<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomeSlideResource\Pages;

use App\Filament\Resources\HomeSlideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHomeSlide extends CreateRecord
{
    protected static string $resource = HomeSlideResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return HomeSlideResource::withDisplayDuration($data, $this->data);
    }
}
