<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingRateGroupResource\RelationManagers;

use App\Modules\Commerce\Domain\Enums\ShippingLocationScope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('scope_type')
                ->label(__('admin.shipping_rates.fields.scope_type'))
                ->options([
                    ShippingLocationScope::City->value => 'City',
                    ShippingLocationScope::District->value => 'District',
                    ShippingLocationScope::Zone->value => 'Zone',
                ])
                ->required()
                ->native(false),
            Forms\Components\TextInput::make('bosta_location_id')
                ->label(__('admin.shipping_rates.fields.bosta_location_id'))
                ->required()
                ->maxLength(64)
                ->extraInputAttributes(['dir' => 'ltr'])
                ->unique(
                    table: 'shipping_rate_locations',
                    column: 'bosta_location_id',
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule->where('scope_type', $get('scope_type')),
                ),
            Forms\Components\TextInput::make('bosta_location_name')
                ->label(__('admin.shipping_rates.fields.bosta_location_name'))
                ->maxLength(191),
            Forms\Components\TextInput::make('bosta_location_name_ar')
                ->label(__('admin.shipping_rates.fields.bosta_location_name_ar'))
                ->maxLength(191),
            Forms\Components\Toggle::make('is_active')
                ->label(__('admin.shipping_rates.fields.location_active'))
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.shipping_rates.sections.locations'))
            ->description(__('admin.shipping_rates.help.locations'))
            ->columns([
                Tables\Columns\TextColumn::make('scope_type')
                    ->label(__('admin.shipping_rates.fields.scope_type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('bosta_location_id')
                    ->label(__('admin.shipping_rates.fields.bosta_location_id'))
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('bosta_location_name')
                    ->label(__('admin.shipping_rates.fields.bosta_location_name')),
                Tables\Columns\TextColumn::make('bosta_location_name_ar')
                    ->label(__('admin.shipping_rates.fields.bosta_location_name_ar')),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('admin.shipping_rates.fields.location_active'))
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
