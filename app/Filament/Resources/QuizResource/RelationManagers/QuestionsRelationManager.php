<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptQuestion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Manages Questions that already belong to the current Quiz (questions.quiz_id).
 * Uses the existing Quiz::questions() hasMany — no second join table.
 */
final class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        $count = (int) $ownerRecord->questions()->count();

        return (string) __('admin.quizzes.relations.questions.title', ['count' => $count]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('question_type')
                ->label(__('admin.common.fields.type'))
                ->options(self::adminQuestionTypeOptions())
                ->required()
                ->live()
                ->default(QuestionType::SingleChoice->value),
            Forms\Components\Select::make('difficulty')
                ->label(__('admin.interactive_activities.fields.difficulty'))
                ->options(collect(QuestionDifficulty::cases())->mapWithKeys(
                    fn (QuestionDifficulty $c): array => [$c->value => __('admin.questions.difficulty.'.$c->value)],
                ))
                ->required()
                ->default(QuestionDifficulty::Medium->value),
            Forms\Components\Select::make('status')
                ->label(__('admin.common.fields.status'))
                ->options(collect(QuestionStatus::cases())->mapWithKeys(
                    fn (QuestionStatus $c): array => [$c->value => __('admin.questions.status.'.$c->value)],
                ))
                ->required()
                ->default(QuestionStatus::Published->value),
            Forms\Components\TextInput::make('points')
                ->label(__('admin.questions.fields.points'))
                ->numeric()
                ->minValue(0)
                ->default(1)
                ->required(),
            Forms\Components\Textarea::make('body.ar')
                ->label(__('admin.questions.fields.body_ar'))
                ->required()
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('body.en')
                ->label(__('admin.questions.fields.body_en'))
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('explanation.ar')
                ->label(__('admin.questions.fields.explanation_ar'))
                ->rows(2)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('explanation.en')
                ->label(__('admin.questions.fields.explanation_en'))
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Repeater::make('options')
                ->relationship()
                ->label(__('admin.questions.fields.options'))
                ->schema([
                    Forms\Components\TextInput::make('label.ar')
                        ->label(__('admin.questions.fields.option_label_ar'))
                        ->required(),
                    Forms\Components\TextInput::make('label.en')
                        ->label(__('admin.questions.fields.option_label_en')),
                    Forms\Components\Toggle::make('is_correct')
                        ->label(__('admin.questions.fields.is_correct'))
                        ->default(false)
                        ->helperText(__('admin.questions.fields.is_correct_help')),
                    Forms\Components\Select::make('meta.side')
                        ->label(__('admin.questions.fields.match_side'))
                        ->options([
                            'left' => __('admin.questions.fields.match_side_left'),
                            'right' => __('admin.questions.fields.match_side_right'),
                        ])
                        ->visible(fn (Get $get): bool => ($get('../../question_type') ?? null) === QuestionType::Matching->value),
                    Forms\Components\TextInput::make('meta.match_key')
                        ->label(__('admin.questions.fields.match_key'))
                        ->helperText(__('admin.questions.fields.match_key_help'))
                        ->visible(fn (Get $get): bool => ($get('../../question_type') ?? null) === QuestionType::Matching->value)
                        ->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\Hidden::make('sort_order')->default(0),
                ])
                ->orderColumn('sort_order')
                ->reorderable()
                ->defaultItems(fn (Get $get): int => match ($get('question_type')) {
                    QuestionType::LongAnswer->value => 0,
                    default => 2,
                })
                ->minItems(fn (Get $get): int => match ($get('question_type')) {
                    QuestionType::SingleChoice->value,
                    QuestionType::MultipleChoice->value,
                    QuestionType::TrueFalse->value,
                    QuestionType::Matching->value,
                    QuestionType::Ordering->value => 2,
                    default => 0,
                })
                ->visible(fn (Get $get): bool => in_array($get('question_type'), [
                    QuestionType::SingleChoice->value,
                    QuestionType::MultipleChoice->value,
                    QuestionType::TrueFalse->value,
                    QuestionType::Matching->value,
                    QuestionType::Ordering->value,
                ], true))
                ->helperText(fn (Get $get): ?string => match ($get('question_type')) {
                    QuestionType::Matching->value => __('admin.questions.helpers.matching_options'),
                    QuestionType::Ordering->value => __('admin.questions.helpers.ordering_options'),
                    QuestionType::SingleChoice->value => __('admin.questions.helpers.single_choice_options'),
                    QuestionType::MultipleChoice->value => __('admin.questions.helpers.multiple_choice_options'),
                    default => null,
                })
                ->rules([
                    fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        try {
                            self::assertChoiceCorrectness([
                                'question_type' => $get('question_type'),
                                'options' => is_array($value) ? array_values($value) : [],
                            ]);
                        } catch (ValidationException $e) {
                            $messages = $e->errors()['options'] ?? [];
                            $fail((string) ($messages[0] ?? __('admin.questions.validation.min_options')));
                        }
                    },
                ])
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('options'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('body')
                    ->label(__('admin.questions.fields.body_ar'))
                    ->formatStateUsing(function (Question $record): string {
                        $ar = (string) ($record->getTranslation('body', 'ar') ?: '');
                        $en = (string) ($record->getTranslation('body', 'en') ?: '');
                        if ($ar !== '' && $en !== '' && $ar !== $en) {
                            return $ar.' / '.$en;
                        }

                        return $ar !== '' ? $ar : ($en !== '' ? $en : '#'.$record->id);
                    })
                    ->wrap()
                    ->searchable(['body']),
                Tables\Columns\TextColumn::make('question_type')
                    ->label(__('admin.common.fields.type'))
                    ->formatStateUsing(fn ($state): string => self::typeLabel($state))
                    ->badge(),
                Tables\Columns\TextColumn::make('points')
                    ->label(__('admin.questions.fields.points')),
                Tables\Columns\TextColumn::make('options_count')
                    ->label(__('admin.questions.table.options_count'))
                    ->formatStateUsing(fn ($state, Question $record): string => in_array(
                        $record->question_type,
                        [QuestionType::LongAnswer, QuestionType::ShortAnswer, QuestionType::Numeric, QuestionType::FillBlank],
                        true,
                    ) ? '—' : (string) $state),
                Tables\Columns\IconColumn::make('is_legacy')
                    ->label(__('admin.questions.table.legacy'))
                    ->boolean()
                    ->getStateUsing(fn (Question $record): bool => self::hasLegacyMap($record->id))
                    ->trueIcon('heroicon-o-archive-box')
                    ->falseIcon('heroicon-o-minus'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('admin.quizzes.relations.questions.add'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['sort_order'] = (int) $this->getOwnerRecord()->questions()->max('sort_order') + 1;
                        $data['status'] = $data['status'] ?? QuestionStatus::Published->value;
                        $data['difficulty'] = $data['difficulty'] ?? QuestionDifficulty::Medium->value;
                        self::assertChoiceCorrectness($data);

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(__('admin.common.actions.edit'))
                    ->mutateFormDataUsing(function (array $data): array {
                        self::assertChoiceCorrectness($data);

                        return $data;
                    }),
                Tables\Actions\DeleteAction::make()
                    ->label(__('admin.common.actions.delete'))
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.quizzes.relations.questions.delete_heading'))
                    ->modalDescription(__('admin.quizzes.relations.questions.delete_description'))
                    ->before(function (Question $record, Tables\Actions\DeleteAction $action): void {
                        if (self::questionHasHistoricalAnswers($record)) {
                            Notification::make()
                                ->title(__('admin.quizzes.relations.questions.delete_blocked_title'))
                                ->body(__('admin.quizzes.relations.questions.delete_blocked_body'))
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->emptyStateHeading(__('admin.quizzes.relations.questions.empty_heading'))
            ->emptyStateDescription(__('admin.quizzes.relations.questions.empty_description'));
    }

    /**
     * @return array<string, string>
     */
    public static function adminQuestionTypeOptions(): array
    {
        $keys = [
            QuestionType::SingleChoice,
            QuestionType::MultipleChoice,
            QuestionType::LongAnswer,
            QuestionType::Matching,
            QuestionType::Ordering,
            QuestionType::TrueFalse,
            QuestionType::ShortAnswer,
            QuestionType::FillBlank,
            QuestionType::Numeric,
            QuestionType::DragDrop,
        ];

        $out = [];
        foreach ($keys as $type) {
            $out[$type->value] = self::typeLabel($type);
        }

        return $out;
    }

    public static function typeLabel(mixed $type): string
    {
        $value = $type instanceof QuestionType ? $type->value : (string) $type;

        return (string) __('admin.questions.types.'.$value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertChoiceCorrectness(array $data): void
    {
        $type = (string) ($data['question_type'] ?? '');

        if (! isset($data['options']) || ! is_array($data['options'])) {
            if (in_array($type, [
                QuestionType::SingleChoice->value,
                QuestionType::MultipleChoice->value,
                QuestionType::TrueFalse->value,
                QuestionType::Matching->value,
                QuestionType::Ordering->value,
            ], true)) {
                // Options may be hydrated via relationship after mutate — skip if absent.
                return;
            }

            return;
        }

        $options = array_values($data['options']);
        $correctCount = count(array_filter(
            $options,
            static fn ($row): bool => (bool) ($row['is_correct'] ?? false),
        ));

        if (in_array($type, [QuestionType::SingleChoice->value, QuestionType::TrueFalse->value], true)) {
            if (count($options) < 2) {
                throw ValidationException::withMessages([
                    'options' => __('admin.questions.validation.min_options'),
                ]);
            }
            if ($correctCount !== 1) {
                throw ValidationException::withMessages([
                    'options' => __('admin.questions.validation.exactly_one_correct'),
                ]);
            }
        }

        if ($type === QuestionType::MultipleChoice->value) {
            if (count($options) < 2) {
                throw ValidationException::withMessages([
                    'options' => __('admin.questions.validation.min_options'),
                ]);
            }
            if ($correctCount < 1) {
                throw ValidationException::withMessages([
                    'options' => __('admin.questions.validation.at_least_one_correct'),
                ]);
            }
        }

        if ($type === QuestionType::Matching->value) {
            if (count($options) < 2) {
                throw ValidationException::withMessages([
                    'options' => __('admin.questions.validation.min_options'),
                ]);
            }
            foreach ($options as $row) {
                $side = $row['meta']['side'] ?? null;
                $key = trim((string) ($row['meta']['match_key'] ?? ''));
                if (! in_array($side, ['left', 'right'], true) || $key === '') {
                    throw ValidationException::withMessages([
                        'options' => __('admin.questions.validation.matching_meta_required'),
                    ]);
                }
            }
        }

        if ($type === QuestionType::Ordering->value && count($options) < 2) {
            throw ValidationException::withMessages([
                'options' => __('admin.questions.validation.min_options'),
            ]);
        }
    }

    public static function questionHasHistoricalAnswers(Question $question): bool
    {
        if (QuizAttemptAnswer::query()->where('question_id', $question->id)->exists()) {
            return true;
        }

        return QuizAttemptQuestion::query()->where('question_id', $question->id)->exists();
    }

    private static function hasLegacyMap(int $questionId): bool
    {
        if (! Schema::hasTable('legacy_import_maps')) {
            return false;
        }

        return DB::table('legacy_import_maps')
            ->where('entity_type', 'question')
            ->where('local_id', $questionId)
            ->exists();
    }
}
