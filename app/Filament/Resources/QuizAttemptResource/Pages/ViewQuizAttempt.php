<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizAttemptResource\Pages;

use App\Filament\Resources\QuizAttemptResource;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

final class ViewQuizAttempt extends ViewRecord
{
    protected static string $resource = QuizAttemptResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        /** @var \App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt $record */
        $record = $this->record;
        $record->loadMissing(['answers.question', 'user', 'quiz']);

        $answerEntries = [];
        foreach ($record->answers as $answer) {
            /** @var QuizAttemptAnswer $answer */
            $stem = $answer->question
                ? (string) $answer->question->getTranslation('body', app()->getLocale())
                : 'Question #'.$answer->question_id;

            $answerEntries[] = Infolists\Components\Section::make($stem)
                ->schema([
                    Infolists\Components\TextEntry::make('text_'.$answer->id)
                        ->label('Student answer')
                        ->state((string) ($answer->text_answer ?: '—')),
                    Infolists\Components\TextEntry::make('pending_'.$answer->id)
                        ->label('Needs review')
                        ->state($answer->needs_manual_review ? 'Yes' : 'No'),
                    Infolists\Components\TextEntry::make('points_'.$answer->id)
                        ->label('Points awarded')
                        ->state($answer->points_awarded !== null ? (string) $answer->points_awarded : '—'),
                ]);
        }

        return $infolist->schema([
            Infolists\Components\Section::make('Attempt')
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')->label('Student'),
                    Infolists\Components\TextEntry::make('quiz.title')
                        ->label('Quiz')
                        ->state(fn () => (string) ($record->quiz?->getTranslation('title', app()->getLocale()) ?? '—')),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('score'),
                    Infolists\Components\TextEntry::make('max_score'),
                    Infolists\Components\TextEntry::make('submitted_at')->dateTime(),
                ])
                ->columns(2),
            ...$answerEntries,
        ]);
    }
}
