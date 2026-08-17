<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionResource\Pages;
use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use App\Modules\Assessment\Application\Services\QuestionDuplicationService;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Assessment';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('question_bank_id')
                ->label('Question Bank')
                ->options(fn () => QuestionBank::query()->get()->mapWithKeys(
                    fn (QuestionBank $b) => [$b->id => $b->getTranslation('title', app()->getLocale())]
                ))
                ->searchable()
                ->nullable(),
            Forms\Components\Select::make('quiz_id')
                ->relationship('quiz', 'id')
                ->label('Fixed Quiz (optional)')
                ->nullable()
                ->helperText('Leave empty for bank-only reusable questions.'),
            Forms\Components\Select::make('question_type')
                ->options(collect(QuestionType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->live(),
            Forms\Components\Select::make('difficulty')
                ->options(collect(QuestionDifficulty::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionDifficulty::Medium->value),
            Forms\Components\Select::make('status')
                ->options(collect(QuestionStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionStatus::Published->value),
            Forms\Components\TextInput::make('points')->numeric()->default(1)->required(),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            Forms\Components\Select::make('tags')
                ->relationship('tags', 'slug')
                ->multiple()
                ->preload()
                ->createOptionForm([
                    Forms\Components\TextInput::make('slug')->required(),
                    Forms\Components\TextInput::make('name.ar')->label('Name AR')->required(),
                    Forms\Components\TextInput::make('name.en')->label('Name EN'),
                ])
                ->helperText('Optional tags for filtering and generated quizzes'),
            Forms\Components\Textarea::make('body.ar')->label('Body (AR)')->required()->columnSpanFull(),
            Forms\Components\Textarea::make('body.en')->label('Body (EN)')->columnSpanFull(),
            Forms\Components\Textarea::make('explanation.ar')->label('Explanation (AR)')->columnSpanFull(),
            Forms\Components\Textarea::make('explanation.en')->label('Explanation (EN)')->columnSpanFull(),

            Forms\Components\Repeater::make('options')
                ->relationship()
                ->schema([
                    Forms\Components\TextInput::make('label.ar')->label('Label AR')->required(),
                    Forms\Components\TextInput::make('label.en')->label('Label EN'),
                    Forms\Components\Toggle::make('is_correct')->default(false),
                    Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                    Forms\Components\KeyValue::make('meta')->nullable(),
                ])
                ->visible(fn (Get $get) => in_array($get('question_type'), [
                    QuestionType::SingleChoice->value,
                    QuestionType::MultipleChoice->value,
                    QuestionType::TrueFalse->value,
                    QuestionType::Matching->value,
                    QuestionType::Ordering->value,
                ], true))
                ->columnSpanFull(),

            Forms\Components\KeyValue::make('answer_key')
                ->label('Answer key')
                ->helperText('short/fill: accepted JSON list via key "accepted". numeric: value + tolerance. interactive: expected payload.')
                ->visible(fn (Get $get) => in_array($get('question_type'), [
                    QuestionType::ShortAnswer->value,
                    QuestionType::FillBlank->value,
                    QuestionType::Numeric->value,
                    QuestionType::InteractiveHtml->value,
                ], true))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('interactive_type')
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value)
                ->helperText('e.g. drag_drop, hotspot, custom'),
            Forms\Components\KeyValue::make('interactive_config')
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value),
            Forms\Components\FileUpload::make('interactive_html_upload')
                ->label('Activity HTML')
                ->acceptedFileTypes(['text/html', 'application/xhtml+xml'])
                ->disk('local')
                ->directory('tmp/interactive-uploads')
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value)
                ->dehydrated(false),
            Forms\Components\Select::make('interactive_activity_id')
                ->label('Linked Interactive Activity')
                ->relationship('interactiveActivity', 'id')
                ->searchable()
                ->nullable()
                ->visible(fn (Get $get) => in_array($get('question_type'), [
                    QuestionType::InteractiveHtml->value,
                    QuestionType::InteractiveActivity->value,
                ], true))
                ->helperText('Prefer first-class Interactive Activities for full HTML packages.'),
            Forms\Components\TextInput::make('interactive_path')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('body')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('question_type')->badge(),
                Tables\Columns\TextColumn::make('difficulty')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('bank.title')->label('Bank'),
                Tables\Columns\TextColumn::make('points'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('question_type')
                    ->options(collect(QuestionType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('difficulty')
                    ->options(collect(QuestionDifficulty::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(QuestionStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('question_bank_id')->relationship('bank', 'id'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (Question $record): void {
                        $copy = app(QuestionDuplicationService::class)->duplicate($record);
                        Notification::make()->title('Question duplicated #'.$copy->id)->success()->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestions::route('/'),
            'create' => Pages\CreateQuestion::route('/create'),
            'edit' => Pages\EditQuestion::route('/{record}/edit'),
        ];
    }
}
