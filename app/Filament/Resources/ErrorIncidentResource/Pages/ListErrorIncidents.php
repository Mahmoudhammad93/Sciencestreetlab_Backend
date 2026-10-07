<?php

declare(strict_types=1);

namespace App\Filament\Resources\ErrorIncidentResource\Pages;

use App\Filament\Resources\ErrorIncidentResource;
use Filament\Resources\Pages\ListRecords;

class ListErrorIncidents extends ListRecords
{
    protected static string $resource = ErrorIncidentResource::class;
}
