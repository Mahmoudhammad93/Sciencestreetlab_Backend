<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuizAttemptResource\Pages;
use App\Modules\Assessment\Application\Services\ManualQuizReviewService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class QuizAttemptResource extends Resource
{
    protected static ?string $model = QuizAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.assessment');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.quiz_attempts.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.quiz_attempts.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.quiz_attempts.nav');
    }

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
                ->label(__('admin.quiz_attempts.fields.student'))
                ->content(fn (?QuizAttempt $record): string => $record?->user?->name ?? '—'),
            Forms\Components\Placeholder::make('quiz_title')
                ->label(__('admin.quiz_attempts.fields.quiz'))
                ->content(fn (?QuizAttempt $record): string => (string) ($record?->quiz?->getTranslation('title', app()->getLocale()) ?? '—')),
            Forms\Components\Placeholder::make('submitted')
                ->label(__('admin.quiz_attempts.fields.submitted'))
                ->content(fn (?QuizAttempt $record): string => $record?->submitted_at?->toDateTimeString() ?? '—'),
            Forms\Components\Placeholder::make('auto_score')
                ->label(__('admin.quiz_attempts.fields.score_so_far'))
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
                Tables\Columns\TextColumn::make('id')->label(__('admin.quiz_attempts.table.attempt_number'))->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label(__('admin.quiz_attempts.fields.student'))->searchable(),
                Tables\Columns\TextColumn::make('quiz.title')
                    ->label(__('admin.quiz_attempts.fields.quiz'))
                    ->formatStateUsing(fn ($state, QuizAttempt $record): string => (string) (
                        $record->quiz?->getTranslation('title', app()->getLocale()) ?? '—'
                    )),
                Tables\Columns\TextColumn::make('status')->label(__('admin.common.fields.status'))->badge(),
                Tables\Columns\TextColumn::make('pending_count')
                    ->label(__('admin.quiz_attempts.table.pending_answers'))
                    ->getStateUsing(fn (QuizAttempt $record): int => $record->answers
                        ->where('needs_manual_review', true)
                        ->count()),
                Tables\Columns\TextColumn::make('submitted_at')->label(__('admin.quiz_attempts.fields.submitted'))->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('grade')
                    ->label(__('admin.quiz_attempts.actions.grade'))
                    ->icon('heroicon-m-check-badge')
                    ->visible(fn (QuizAttempt $record): bool => $record->answers
                        ->contains(fn (QuizAttemptAnswer $a) => (bool) $a->needs_manual_review))
                    ->form(function (QuizAttempt $record): array {
                        $fields = [];
                        foreach ($record->answers->where('needs_manual_review', true) as $answer) {
                            $question = $answer->question;
                            $title = $question
                                ? (string) $question->getTranslation('body', app()->getLocale())
                                : str_replace('…', (string) $answer->question_id, __('admin.quiz_attempts.grade_form.question_fallback'));
                            $max = (float) ($question?->points ?? 0);
                            $fields[] = Forms\Components\Section::make($title)
                                ->schema([
                                    Forms\Components\Placeholder::make('answer_text_'.$answer->id)
                                        ->label(__('admin.quiz_attempts.grade_form.student_answer'))
                                        ->content((string) ($answer->text_answer ?: '—'))
                                        ->visible(fn (): bool => $question?->question_type !== QuestionType::ImageUpload),
                                    Forms\Components\Placeholder::make('answer_images_'.$answer->id)
                                        ->label(__('admin.quiz_attempts.grade_form.uploaded_images'))
                                        ->content(fn (): HtmlString => self::imagePreviewHtml($answer, $record))
                                        ->visible(fn (): bool => $question?->question_type === QuestionType::ImageUpload),
                                    Forms\Components\TextInput::make('points_'.$answer->id)
                                        ->label(__('admin.quiz_attempts.grade_form.points', ['max' => $max]))
                                        ->numeric()
                                        ->required()
                                        ->minValue(0)
                                        ->maxValue($max > 0 ? $max : 999)
                                        ->default((float) ($answer->points_awarded ?? 0)),
                                    Forms\Components\Toggle::make('correct_'.$answer->id)
                                        ->label(__('admin.quiz_attempts.grade_form.mark_correct'))
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
                            ->title(__('admin.quiz_attempts.notifications.graded'))
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

    private static function imagePreviewHtml(QuizAttemptAnswer $answer, QuizAttempt $attempt): HtmlString
    {
        $answer->loadMissing('media');
        $mediaItems = $answer->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION);
        if ($mediaItems->isEmpty()) {
            return new HtmlString('<span>—</span>');
        }

        $parts = $mediaItems->map(function (Media $media) use ($attempt, $answer): string {
            $url = route('admin.quiz-attempt-images.show', [
                'attempt' => $attempt->id,
                'question' => $answer->question_id,
                'media' => $media->id,
            ]);

            return sprintf(
                '<a href="%s" target="_blank" rel="noopener" class="block mb-2"><img src="%s" alt="answer" style="max-width:320px;max-height:240px;border-radius:8px;object-fit:contain;background:#111" /></a>',
                e($url),
                e($url),
            );
        })->implode('');

        return new HtmlString($parts);
    }
}
