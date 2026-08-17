<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuizResource\Pages;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
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

    protected static ?string $navigationGroup = 'Assessment';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title.ar')->label('Title (AR)')->required(),
            Forms\Components\TextInput::make('title.en')->label('Title (EN)'),
            Forms\Components\Textarea::make('instructions.ar')->label('Instructions (AR)'),
            Forms\Components\TextInput::make('passing_score')->numeric()->default(70)->required(),
            Forms\Components\TextInput::make('max_attempts')->numeric()->nullable(),
            Forms\Components\TextInput::make('time_limit_seconds')->numeric()->nullable(),
            Forms\Components\Toggle::make('shuffle_questions')->default(false),
            Forms\Components\Toggle::make('is_required')->default(true),
            Forms\Components\Select::make('selection_mode')
                ->options(collect(QuizSelectionMode::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->live()
                ->default(QuizSelectionMode::Fixed->value),
            Forms\Components\Select::make('questionBanks')
                ->relationship('questionBanks', 'id')
                ->multiple()
                ->preload()
                ->visible(fn (Get $get) => $get('selection_mode') === QuizSelectionMode::Generated->value)
                ->helperText('Banks used for random generation'),
            Forms\Components\Select::make('interactiveActivities')
                ->relationship('interactiveActivities', 'id')
                ->multiple()
                ->preload()
                ->helperText('Attach first-class Interactive Activities to this quiz (mixed assessment).'),
            Forms\Components\KeyValue::make('selection_config')
                ->visible(fn (Get $get) => $get('selection_mode') === QuizSelectionMode::Generated->value)
                ->helperText('Example keys: total_questions=10 or difficulty[easy]=5'),
            Forms\Components\MorphToSelect::make('quizable')
                ->types([
                    Forms\Components\MorphToSelect\Type::make(\App\Modules\Learning\Infrastructure\Persistence\Models\Lesson::class)
                        ->titleAttribute('slug'),
                ])
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id'),
            Tables\Columns\TextColumn::make('title')->limit(40),
            Tables\Columns\TextColumn::make('selection_mode')->badge(),
            Tables\Columns\TextColumn::make('passing_score'),
            Tables\Columns\IconColumn::make('is_required')->boolean(),
            Tables\Columns\TextColumn::make('questions_count')->counts('questions'),
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
}
