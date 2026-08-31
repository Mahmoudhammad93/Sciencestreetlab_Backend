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
            Forms\Components\Section::make('Coupon details')
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state))),
                    Forms\Components\Select::make('type')
                        ->options(collect(CouponType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                        ->required(),
                    Forms\Components\TextInput::make('value')
                        ->numeric()
                        ->required()
                        ->helperText('Fixed amount (EGP) or percentage depending on type.'),
                    Forms\Components\TextInput::make('min_order_amount')
                        ->label('Minimum order amount')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\Toggle::make('is_active')->default(true),
                ])
                ->columns(2),
            Forms\Components\Section::make('Usage limits')
                ->schema([
                    Forms\Components\TextInput::make('max_uses')
                        ->label('Maximum uses')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave empty for unlimited redemptions.'),
                    Forms\Components\TextInput::make('used_count')
                        ->label('Times used')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit')
                        ->helperText(fn (?Coupon $record): ?string => $record && $record->max_uses
                            ? sprintf('%d of %d uses consumed.', $record->used_count, $record->max_uses)
                            : null),
                ])
                ->columns(2),
            Forms\Components\Section::make('Schedule')
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at'),
                    Forms\Components\DateTimePicker::make('expires_at'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('value'),
                Tables\Columns\TextColumn::make('used_count')
                    ->label('Uses')
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
