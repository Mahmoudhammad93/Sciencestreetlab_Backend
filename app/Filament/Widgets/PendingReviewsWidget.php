<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\CompetitionSubmissionResource;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingReviewsWidget extends BaseWidget
{
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Photos waiting for review';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CompetitionSubmission::query()
                    ->with(['participant.user', 'participant.competition', 'media'])
                    ->where('status', SubmissionStatus::Pending)
                    ->latest('submitted_at')
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->emptyStateHeading('No pending photos')
            ->emptyStateDescription('The 100 Photos Challenge review queue is clear.')
            ->columns([
                Tables\Columns\ImageColumn::make('photo')
                    ->label('Photo')
                    ->getStateUsing(fn (CompetitionSubmission $record) => $record->getFirstMediaUrl('photo'))
                    ->square(),
                Tables\Columns\TextColumn::make('participant.user.name')
                    ->label('Student')
                    ->searchable(),
                Tables\Columns\TextColumn::make('participant.competition.slug')
                    ->label('Competition'),
                Tables\Columns\TextColumn::make('sample_number')
                    ->label('Sample'),
                Tables\Columns\TextColumn::make('photo_index')
                    ->label('Photo #'),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-eye')
                    ->url(fn (CompetitionSubmission $record): string => CompetitionSubmissionResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
