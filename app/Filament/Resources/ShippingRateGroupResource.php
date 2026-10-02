<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ShippingRateGroupResource\Pages;
use App\Filament\Resources\ShippingRateGroupResource\RelationManagers\LocationsRelationManager;
use App\Modules\Commerce\Infrastructure\Persistence\Models\ShippingRateGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ShippingRateGroupResource extends Resource
{
    protected static ?string $model = ShippingRateGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.shipping_rates');
    }

    public static function getModelLabel(): string
    {
        return __('admin.shipping_rates.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.shipping_rates.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.shipping_rates.sections.general'))->schema([
                Forms\Components\TextInput::make('code')
                    ->label(__('admin.shipping_rates.fields.code'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(64)
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\TextInput::make('name.ar')
                    ->label(__('admin.shipping_rates.fields.name_ar'))
                    ->required()
                    ->maxLength(191),
                Forms\Components\TextInput::make('name.en')
                    ->label(__('admin.shipping_rates.fields.name_en'))
                    ->required()
                    ->maxLength(191),
                Forms\Components\TextInput::make('price')
                    ->label(__('admin.shipping_rates.fields.price'))
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01),
                Forms\Components\Toggle::make('is_active')
                    ->label(__('admin.shipping_rates.fields.is_active'))
                    ->default(true),
                Forms\Components\TextInput::make('sort_order')
                    ->label(__('admin.shipping_rates.fields.sort_order'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ])->columns(2),
            Forms\Components\Placeholder::make('unmapped_help')
                ->label('')
                ->content(__('admin.shipping_rates.help.unmapped')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('admin.shipping_rates.fields.code'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.shipping_rates.fields.name_ar'))
                    ->formatStateUsing(fn (ShippingRateGroup $record): string => (string) ($record->getTranslation('name', 'ar') ?: $record->code)),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.shipping_rates.table.price'))
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('locations_count')
                    ->counts('locations')
                    ->label(__('admin.shipping_rates.table.locations_count')),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('admin.shipping_rates.fields.is_active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('admin.shipping_rates.fields.sort_order'))
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            LocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingRateGroups::route('/'),
            'create' => Pages\CreateShippingRateGroup::route('/create'),
            'edit' => Pages\EditShippingRateGroup::route('/{record}/edit'),
        ];
    }
}
