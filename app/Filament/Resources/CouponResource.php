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

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.coupons');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.coupons');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.coupons');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.coupons.sections.details'))
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label(__('admin.coupons.fields.code'))
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state))),
                    Forms\Components\Select::make('type')
                        ->label(__('admin.coupons.fields.type'))
                        ->options(collect(CouponType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                        ->required(),
                    Forms\Components\TextInput::make('value')
                        ->label(__('admin.coupons.fields.value'))
                        ->numeric()
                        ->required()
                        ->helperText(__('admin.coupons.fields.value_help')),
                    Forms\Components\TextInput::make('min_order_amount')
                        ->label(__('admin.coupons.fields.min_order'))
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\Toggle::make('is_active')
                        ->label(__('admin.coupons.fields.is_active'))
                        ->default(true),
                ])
                ->columns(2),
            Forms\Components\Section::make(__('admin.coupons.sections.usage_limits'))
                ->schema([
                    Forms\Components\TextInput::make('max_uses')
                        ->label(__('admin.coupons.fields.max_uses'))
                        ->numeric()
                        ->minValue(1)
                        ->helperText(__('admin.coupons.fields.max_uses_help')),
                    Forms\Components\TextInput::make('used_count')
                        ->label(__('admin.coupons.fields.used_count'))
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit')
                        ->helperText(fn (?Coupon $record): ?string => $record && $record->max_uses
                            ? sprintf(__('admin.coupons.fields.used_count_help'), $record->used_count, $record->max_uses)
                            : null),
                ])
                ->columns(2),
            Forms\Components\Section::make(__('admin.coupons.sections.schedule'))
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at')
                        ->label(__('admin.coupons.fields.starts_at')),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label(__('admin.coupons.fields.expires_at')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('admin.coupons.fields.code'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('admin.coupons.fields.type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('value')
                    ->label(__('admin.coupons.fields.value')),
                Tables\Columns\TextColumn::make('used_count')
                    ->label(__('admin.coupons.table.uses'))
                    ->formatStateUsing(fn (Coupon $record): string => $record->max_uses
                        ? "{$record->used_count} / {$record->max_uses}"
                        : (string) $record->used_count),
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
