<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\ImageDropzone;
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

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.learning');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.achievements');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.achievements');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.achievements');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.achievements.sections.achievement'))->schema([
                Forms\Components\TextInput::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\Select::make('category')
                    ->label(__('admin.common.fields.category'))
                    ->options(collect(AchievementCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required(),
                Forms\Components\TextInput::make('points')
                    ->label(__('admin.common.fields.value'))
                    ->numeric()
                    ->default(0)
                    ->required(),
                Forms\Components\TextInput::make('badge_color')
                    ->label(__('admin.achievements.fields.badge_color'))
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\TextInput::make('name.ar')->label(__('admin.achievements.fields.name_ar'))->required(),
                Forms\Components\TextInput::make('name.en')->label(__('admin.achievements.fields.name_en')),
                Forms\Components\Textarea::make('description.ar')->label(__('admin.achievements.fields.description_ar')),
                Forms\Components\Textarea::make('description.en')->label(__('admin.achievements.fields.description_en')),
                Forms\Components\Toggle::make('is_active')->label(__('admin.common.fields.active'))->default(true),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.achievements.sections.icon'))->schema([
                ImageDropzone::make(
                    'icon_path',
                    'achievements',
                    __('admin.achievements.fields.icon'),
                    __('admin.achievements.fields.icon_help')
                ),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('icon_path')
                    ->label(__('admin.achievements.table.icon'))
                    ->getStateUsing(fn (Achievement $record): ?string => ImageDropzone::publicUrl($record->icon_path))
                    ->circular(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.common.fields.name'))
                    ->formatStateUsing(fn ($record) => $record->getTranslation('name', 'ar')),
                Tables\Columns\TextColumn::make('category')
                    ->label(__('admin.common.fields.category'))
                    ->badge(),
                Tables\Columns\TextColumn::make('points')->label(__('admin.common.fields.value')),
                Tables\Columns\IconColumn::make('is_active')->label(__('admin.common.fields.active'))->boolean(),
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
