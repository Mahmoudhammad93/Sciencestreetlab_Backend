<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompetitionSubmissionResource\Pages;

use App\Filament\Resources\CompetitionSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListCompetitionSubmissions extends ListRecords
{
    protected static string $resource = CompetitionSubmissionResource::class;
}
