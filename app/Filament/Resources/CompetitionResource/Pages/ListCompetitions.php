<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompetitionResource\Pages;

use App\Filament\Resources\CompetitionResource;
use Filament\Resources\Pages\ListRecords;

class ListCompetitions extends ListRecords
{
    protected static string $resource = CompetitionResource::class;
}
