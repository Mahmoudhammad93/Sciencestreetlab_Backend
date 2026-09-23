<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuizAttemptResource\Pages;
use App\Modules\Assessment\Application\Services\ManualQuizReviewService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuizAttemptResource extends Resource
{
    protected static ?string $model = QuizAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Assessment';

    protected static ?string $navigationLabel = 'Written reviews';

    protected static ?string $modelLabel = 'Quiz attempt';

    protected static ?string $pluralModelLabel = 'Written reviews';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'quiz', 'answers.question'])
            ->where(function (Builder $query): void {
                $query->where('status', AttemptStatus::PendingReview)
                    ->orWhereHas('answers', fn (Builder $q) => $q->where('needs_manual_review', true));
            });
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('student')
                ->label('Student')
                ->content(fn (?QuizAttempt $record): string => $record?->user?->name ?? '—'),
            Forms\Components\Placeholder::make('quiz_title')
                ->label('Quiz')
                ->content(fn (?QuizAttempt $record): string => (string) ($record?->quiz?->getTranslation('title', app()->getLocale()) ?? '—')),
            Forms\Components\Placeholder::make('submitted')
                ->label('Submitted')
                ->content(fn (?QuizAttempt $record): string => $record?->submitted_at?->toDateTimeString() ?? '—'),
            Forms\Components\Placeholder::make('auto_score')
                ->label('Score so far')
                ->content(fn (?QuizAttempt $record): string => $record
                    ? ((string) $record->score).' / '.((string) $record->max_score)
                    : '—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Attempt #')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Student')->searchable(),
                Tables\Columns\TextColumn::make('quiz.title')
                    ->label('Quiz')
                    ->formatStateUsing(fn ($state, QuizAttempt $record): string => (string) (
                        $record->quiz?->getTranslation('title', app()->getLocale()) ?? '—'
                    )),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('pending_count')
                    ->label('Pending answers')
                    ->getStateUsing(fn (QuizAttempt $record): int => $record->answers
                        ->where('needs_manual_review', true)
                        ->count()),
                Tables\Columns\TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('grade')
                    ->label('Grade')
                    ->icon('heroicon-m-check-badge')
                    ->visible(fn (QuizAttempt $record): bool => $record->answers
                        ->contains(fn (QuizAttemptAnswer $a) => (bool) $a->needs_manual_review))
                    ->form(function (QuizAttempt $record): array {
                        $fields = [];
                        foreach ($record->answers->where('needs_manual_review', true) as $answer) {
                            $question = $answer->question;
                            $title = $question
                                ? (string) $question->getTranslation('body', app()->getLocale())
                                : 'Question #'.$answer->question_id;
                            $max = (float) ($question?->points ?? 0);
                            $fields[] = Forms\Components\Section::make($title)
                                ->schema([
                                    Forms\Components\Placeholder::make('answer_text_'.$answer->id)
                                        ->label('Student answer')
                                        ->content((string) ($answer->text_answer ?: '—')),
                                    Forms\Components\TextInput::make('points_'.$answer->id)
                                        ->label('Points (max '.$max.')')
                                        ->numeric()
                                        ->required()
                                        ->minValue(0)
                                        ->maxValue($max > 0 ? $max : 999)
                                        ->default((float) ($answer->points_awarded ?? 0)),
                                    Forms\Components\Toggle::make('correct_'.$answer->id)
                                        ->label('Mark correct')
                                        ->default(false),
                                ]);
                        }

                        return $fields;
                    })
                    ->action(function (QuizAttempt $record, array $data): void {
                        $service = app(ManualQuizReviewService::class);

                        foreach ($record->answers->where('needs_manual_review', true) as $answer) {
                            $pointsKey = 'points_'.$answer->id;
                            $correctKey = 'correct_'.$answer->id;
                            if (! array_key_exists($pointsKey, $data)) {
                                continue;
                            }

                            $service->gradeAnswer(
                                $answer,
                                (float) $data[$pointsKey],
                                (bool) ($data[$correctKey] ?? false),
                            );
                        }

                        Notification::make()
                            ->title('Written answers graded')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuizAttempts::route('/'),
            'view' => Pages\ViewQuizAttempt::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
