<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AchievementResource\Pages;
use App\Modules\Gamification\Domain\Enums\AchievementCategory;
use App\Modules\Gamification\Infrastructure\Persistence\Models\Achievement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AchievementResource extends Resource
{
    protected static ?string $model = Achievement::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'Learning';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true),
            Forms\Components\Select::make('category')
                ->options(collect(AchievementCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required(),
            Forms\Components\TextInput::make('points')->numeric()->default(0)->required(),
            Forms\Components\TextInput::make('badge_color')->label('Badge color (#hex)'),
            Forms\Components\TextInput::make('name.ar')->label('Name (AR)')->required(),
            Forms\Components\TextInput::make('name.en')->label('Name (EN)'),
            Forms\Components\Textarea::make('description.ar')->label('Description (AR)'),
            Forms\Components\Textarea::make('description.en')->label('Description (EN)'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('name')->formatStateUsing(fn ($record) => $record->getTranslation('name', 'ar')),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('points'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
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
            'index' => Pages\ListAchievements::route('/'),
            'create' => Pages\CreateAchievement::route('/create'),
            'edit' => Pages\EditAchievement::route('/{record}/edit'),
        ];
    }
}
