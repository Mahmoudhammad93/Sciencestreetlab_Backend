<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CompetitionSubmissionResource\Pages;
use App\Models\User;
use App\Modules\Competition\Application\Services\SubmissionReviewService;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompetitionSubmissionResource extends Resource
{
    protected static ?string $model = CompetitionSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationGroup = 'Competition';

    protected static ?string $navigationLabel = 'Review Queue';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('uuid')->disabled(),
            Forms\Components\TextInput::make('sample_number')->disabled(),
            Forms\Components\TextInput::make('photo_index')->disabled(),
            Forms\Components\Select::make('status')
                ->options(collect(SubmissionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->name]))
                ->disabled(),
            Forms\Components\Textarea::make('description')->disabled(),
            Forms\Components\Textarea::make('scientific_notes')->disabled(),
            Forms\Components\Textarea::make('rejection_reason')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['participant.user', 'participant.competition', 'media']))
            ->defaultSort('submitted_at')
            ->columns([
                Tables\Columns\ImageColumn::make('photo')
                    ->label('Photo')
                    ->getStateUsing(fn (CompetitionSubmission $record) => $record->getFirstMediaUrl('photo'))
                    ->square(),
                Tables\Columns\TextColumn::make('participant.user.name')->label('Student')->searchable(),
                Tables\Columns\TextColumn::make('participant.competition.slug')->label('Competition'),
                Tables\Columns\TextColumn::make('sample_number'),
                Tables\Columns\TextColumn::make('photo_index'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(SubmissionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->name]))
                    ->default(SubmissionStatus::Pending->value),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CompetitionSubmission $record) => $record->status === SubmissionStatus::Pending)
                    ->action(function (CompetitionSubmission $record): void {
                        /** @var User $reviewer */
                        $reviewer = auth()->user();
                        app(SubmissionReviewService::class)->approve($reviewer, $record);

                        Notification::make()->title('Submission approved')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CompetitionSubmission $record) => $record->status === SubmissionStatus::Pending)
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')->required(),
                        Forms\Components\Textarea::make('notes'),
                    ])
                    ->action(function (CompetitionSubmission $record, array $data): void {
                        /** @var User $reviewer */
                        $reviewer = auth()->user();
                        app(SubmissionReviewService::class)->reject(
                            $reviewer,
                            $record,
                            $data['rejection_reason'],
                            $data['notes'] ?? null
                        );

                        Notification::make()->title('Submission rejected')->warning()->send();
                    }),
                Tables\Actions\Action::make('request_revision')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (CompetitionSubmission $record) => $record->status === SubmissionStatus::Pending)
                    ->form([
                        Forms\Components\Textarea::make('notes')->required(),
                    ])
                    ->action(function (CompetitionSubmission $record, array $data): void {
                        /** @var User $reviewer */
                        $reviewer = auth()->user();
                        app(SubmissionReviewService::class)->requestRevision($reviewer, $record, $data['notes']);

                        Notification::make()->title('Revision requested')->info()->send();
                    }),
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitionSubmissions::route('/'),
            'view' => Pages\ViewCompetitionSubmission::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
