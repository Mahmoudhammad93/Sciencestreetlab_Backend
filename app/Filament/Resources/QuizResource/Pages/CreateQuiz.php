<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Resources\QuizResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateQuiz extends CreateRecord
{
    protected static string $resource = QuizResource::class;
}
