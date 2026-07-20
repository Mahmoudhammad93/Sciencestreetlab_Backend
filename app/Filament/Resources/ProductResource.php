<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('General')->schema([
                Forms\Components\TextInput::make('sku')->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Forms\Components\Select::make('type')
                    ->options(collect(ProductType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required(),
                Forms\Components\Toggle::make('is_featured'),
            ])->columns(2),
            Forms\Components\Section::make('Pricing')->schema([
                Forms\Components\TextInput::make('price')->numeric()->required()->prefix('EGP'),
                Forms\Components\TextInput::make('compare_price')->numeric()->prefix('EGP'),
                Forms\Components\TextInput::make('stock_quantity')->numeric(),
                Forms\Components\Toggle::make('manage_stock'),
            ])->columns(2),
            Forms\Components\Section::make('Arabic')->schema([
                Forms\Components\TextInput::make('name.ar')->label('Name (AR)')->required(),
                Forms\Components\Textarea::make('short_description.ar')->label('Short Description (AR)'),
            ]),
            Forms\Components\Section::make('English')->schema([
                Forms\Components\TextInput::make('name.en')->label('Name (EN)'),
                Forms\Components\Textarea::make('short_description.en')->label('Short Description (EN)'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sku')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('price')->money('EGP')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\IconColumn::make('is_featured')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(ProductType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
