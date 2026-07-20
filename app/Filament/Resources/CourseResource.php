<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CourseResource\Pages;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Learning';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true),
            Forms\Components\Select::make('access_type')
                ->options(collect(AccessType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required(),
            Forms\Components\Toggle::make('is_published'),
            Forms\Components\TextInput::make('title.ar')->label('Title (AR)')->required(),
            Forms\Components\TextInput::make('title.en')->label('Title (EN)'),
            Forms\Components\Textarea::make('description.ar')->label('Description (AR)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('access_type')->badge(),
                Tables\Columns\IconColumn::make('is_published')->boolean(),
                Tables\Columns\TextColumn::make('lessons_count')->counts('lessons')->label('Lessons'),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
