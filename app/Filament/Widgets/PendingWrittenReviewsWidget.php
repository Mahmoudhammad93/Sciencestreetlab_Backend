<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\QuizAttemptResource;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingWrittenReviewsWidget extends BaseWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Written answers waiting for review';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                QuizAttempt::query()
                    ->with(['user', 'quiz', 'answers'])
                    ->where('status', AttemptStatus::PendingReview)
                    ->latest('submitted_at')
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->emptyStateHeading('No written answers pending')
            ->emptyStateDescription('Long-answer quiz submissions appear here for teachers to score.')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Student'),
                Tables\Columns\TextColumn::make('quiz.title')
                    ->label('Quiz')
                    ->formatStateUsing(fn ($state, QuizAttempt $record): string => (string) (
                        $record->quiz?->getTranslation('title', app()->getLocale()) ?? '—'
                    )),
                Tables\Columns\TextColumn::make('pending')
                    ->label('Pending')
                    ->getStateUsing(fn (QuizAttempt $record): int => $record->answers
                        ->where('needs_manual_review', true)
                        ->count()),
                Tables\Columns\TextColumn::make('submitted_at')->since()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-pencil-square')
                    ->url(fn (QuizAttempt $record): string => QuizAttemptResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
