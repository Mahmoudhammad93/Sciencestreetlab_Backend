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

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.assessment');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.question_banks');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.question_banks');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.question_banks');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('lesson_id')
                ->label(__('admin.question_banks.fields.lesson'))
                ->options(fn () => Lesson::query()->with('course')->get()->mapWithKeys(
                    fn (Lesson $l) => [$l->id => ($l->course?->slug ?? 'course').' / '.$l->slug]
                ))
                ->searchable()
                ->nullable(),
            Forms\Components\Select::make('status')
                ->label(__('admin.common.fields.status'))
                ->options(collect(QuestionBankStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionBankStatus::Active->value),
            Forms\Components\TextInput::make('title.ar')->label(__('admin.question_banks.fields.title_ar'))->required(),
            Forms\Components\TextInput::make('title.en')->label(__('admin.question_banks.fields.title_en')),
            Forms\Components\Textarea::make('description.ar')->label(__('admin.question_banks.fields.description_ar')),
            Forms\Components\Textarea::make('description.en')->label(__('admin.question_banks.fields.description_en')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label(__('admin.common.fields.title'))->searchable(),
                Tables\Columns\TextColumn::make('lesson.slug')->label(__('admin.question_banks.table.lesson')),
                Tables\Columns\TextColumn::make('status')->label(__('admin.common.fields.status'))->badge(),
                Tables\Columns\TextColumn::make('questions_count')->counts('questions')->label(__('admin.question_banks.table.questions')),
                Tables\Columns\TextColumn::make('updated_at')->label(__('admin.common.fields.updated_at'))->dateTime(),
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
