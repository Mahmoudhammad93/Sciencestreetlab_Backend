<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizAttemptResource\Pages;

use App\Filament\Resources\QuizAttemptResource;
use Filament\Resources\Pages\ListRecords;

final class ListQuizAttempts extends ListRecords
{
    protected static string $resource = QuizAttemptResource::class;
}
