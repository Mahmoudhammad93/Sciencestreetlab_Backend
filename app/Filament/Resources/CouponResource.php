<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CouponResource\Pages;
use App\Modules\Commerce\Domain\Enums\CouponType;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->required()
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state))),
            Forms\Components\Select::make('type')
                ->options(collect(CouponType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required(),
            Forms\Components\TextInput::make('value')->numeric()->required(),
            Forms\Components\TextInput::make('min_order_amount')->numeric(),
            Forms\Components\TextInput::make('max_uses')->numeric(),
            Forms\Components\DateTimePicker::make('starts_at'),
            Forms\Components\DateTimePicker::make('expires_at'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('value'),
                Tables\Columns\TextColumn::make('used_count'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime(),
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
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
