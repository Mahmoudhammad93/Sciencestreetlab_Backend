<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuizResource\Pages;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class QuizResource extends Resource
{
    protected static ?string $model = Quiz::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.assessment');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.quizzes');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.quizzes');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.quizzes');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title.ar')->label(__('admin.quizzes.fields.title_ar'))->required(),
            Forms\Components\TextInput::make('title.en')->label(__('admin.quizzes.fields.title_en')),
            Forms\Components\Textarea::make('instructions.ar')->label(__('admin.quizzes.fields.instructions_ar')),
            Forms\Components\TextInput::make('passing_score')->label(__('admin.quizzes.fields.passing_score'))->numeric()->default(70)->required(),
            Forms\Components\TextInput::make('max_attempts')->label(__('admin.quizzes.fields.max_attempts'))->numeric()->nullable(),
            Forms\Components\TextInput::make('time_limit_seconds')->label(__('admin.quizzes.fields.time_limit_seconds'))->numeric()->nullable(),
            Forms\Components\Toggle::make('shuffle_questions')->label(__('admin.quizzes.fields.shuffle_questions'))->default(false),
            Forms\Components\Toggle::make('is_required')->label(__('admin.quizzes.fields.is_required'))->default(true),
            Forms\Components\Select::make('selection_mode')
                ->label(__('admin.quizzes.fields.selection_mode'))
                ->options(collect(QuizSelectionMode::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->live()
                ->default(QuizSelectionMode::Fixed->value),
            Forms\Components\Select::make('questionBanks')
                ->relationship('questionBanks', 'id')
                ->multiple()
                ->preload()
                ->visible(fn (Get $get) => $get('selection_mode') === QuizSelectionMode::Generated->value)
                ->helperText(__('admin.quizzes.fields.question_banks_help')),
            Forms\Components\Select::make('interactiveActivities')
                ->relationship('interactiveActivities', 'id')
                ->multiple()
                ->preload()
                ->helperText(__('admin.quizzes.fields.interactive_activities_help')),
            Forms\Components\KeyValue::make('selection_config')
                ->visible(fn (Get $get) => $get('selection_mode') === QuizSelectionMode::Generated->value)
                ->helperText(__('admin.quizzes.fields.selection_config_help'))
                ->extraAttributes(['dir' => 'ltr']),
            Forms\Components\Section::make(__('admin.quizzes.sections.lesson_assignment'))
                ->description(__('admin.quizzes.sections.lesson_assignment_description'))
                ->schema([
                    Forms\Components\MorphToSelect::make('quizable')
                        ->label(__('admin.quizzes.fields.attach_lesson'))
                        ->types([
                            Forms\Components\MorphToSelect\Type::make(Lesson::class)
                                ->titleAttribute('slug')
                                ->getOptionLabelFromRecordUsing(
                                    fn (Lesson $record): string => self::lessonOptionLabel($record),
                                ),
                        ])
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->label('#'),
            Tables\Columns\TextColumn::make('title')->label(__('admin.common.fields.title'))->limit(40),
            Tables\Columns\TextColumn::make('quizable.slug')
                ->label(__('admin.quizzes.table.lesson'))
                ->description(fn (Quiz $record): ?string => $record->quizable instanceof Lesson
                    ? ($record->quizable->course?->getTranslation('title', 'en') ?: $record->quizable->course?->slug)
                    : null),
            Tables\Columns\TextColumn::make('selection_mode')->label(__('admin.quizzes.fields.selection_mode'))->badge(),
            Tables\Columns\TextColumn::make('passing_score')->label(__('admin.quizzes.fields.passing_score')),
            Tables\Columns\IconColumn::make('is_required')->label(__('admin.quizzes.fields.is_required'))->boolean(),
            Tables\Columns\TextColumn::make('questions_count')->counts('questions')->label(__('admin.nav.questions')),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuizzes::route('/'),
            'create' => Pages\CreateQuiz::route('/create'),
            'edit' => Pages\EditQuiz::route('/{record}/edit'),
        ];
    }

    public static function lessonOptionLabel(Lesson $lesson): string
    {
        $course = $lesson->course?->slug ?? 'course';
        $title = $lesson->getTranslation('title', 'en') ?: $lesson->getTranslation('title', 'ar') ?: $lesson->slug;

        return $course.' / '.$lesson->slug.' — '.$title;
    }
}
