<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuestionBankResource\Pages;

use App\Filament\Resources\QuestionBankResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateQuestionBank extends CreateRecord
{
    protected static string $resource = QuestionBankResource::class;
}
