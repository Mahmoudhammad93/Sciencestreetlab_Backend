<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionBankResource\Pages;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class QuestionBankResource extends Resource
{
    protected static ?string $model = QuestionBank::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Assessment';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('lesson_id')
                ->label('Lesson')
                ->options(fn () => Lesson::query()->with('course')->get()->mapWithKeys(
                    fn (Lesson $l) => [$l->id => ($l->course?->slug ?? 'course').' / '.$l->slug]
                ))
                ->searchable()
                ->nullable(),
            Forms\Components\Select::make('status')
                ->options(collect(QuestionBankStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionBankStatus::Active->value),
            Forms\Components\TextInput::make('title.ar')->label('Title (AR)')->required(),
            Forms\Components\TextInput::make('title.en')->label('Title (EN)'),
            Forms\Components\Textarea::make('description.ar')->label('Description (AR)'),
            Forms\Components\Textarea::make('description.en')->label('Description (EN)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('lesson.slug')->label('Lesson'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('questions_count')->counts('questions')->label('Questions'),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(QuestionBankStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('lesson_id')->relationship('lesson', 'slug'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestionBanks::route('/'),
            'create' => Pages\CreateQuestionBank::route('/create'),
            'edit' => Pages\EditQuestionBank::route('/{record}/edit'),
        ];
    }
}
