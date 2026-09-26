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

    public function getHeading(): ?string
    {
        return __('admin.widgets.pending_photos.heading');
    }

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
            ->emptyStateHeading(__('admin.widgets.pending_photos.empty_heading'))
            ->emptyStateDescription(__('admin.widgets.pending_photos.empty_description'))
            ->columns([
                Tables\Columns\ImageColumn::make('photo')
                    ->label(__('admin.widgets.pending_photos.table.photo'))
                    ->getStateUsing(fn (CompetitionSubmission $record) => $record->getFirstMediaUrl('photo'))
                    ->square(),
                Tables\Columns\TextColumn::make('participant.user.name')
                    ->label(__('admin.widgets.pending_photos.table.student'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('participant.competition.slug')
                    ->label(__('admin.widgets.pending_photos.table.competition')),
                Tables\Columns\TextColumn::make('sample_number')
                    ->label(__('admin.widgets.pending_photos.table.sample')),
                Tables\Columns\TextColumn::make('photo_index')
                    ->label(__('admin.widgets.pending_photos.table.photo_number')),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label(__('admin.widgets.pending_photos.table.review'))
                    ->icon('heroicon-m-eye')
                    ->url(fn (CompetitionSubmission $record): string => CompetitionSubmissionResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
