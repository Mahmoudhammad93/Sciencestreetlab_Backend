<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuestionBankResource\Pages;

use App\Filament\Resources\QuestionBankResource;
use Filament\Resources\Pages\ListRecords;

final class ListQuestionBanks extends ListRecords
{
    protected static string $resource = QuestionBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
