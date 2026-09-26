<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.orders');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.orders');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.orders');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('order_number')->disabled(),
            Forms\Components\Select::make('status')->options([
                'pending' => 'Pending',
                'awaiting_payment' => 'Awaiting Payment',
                'paid' => 'Paid',
                'processing' => 'Processing',
                'shipped' => 'Shipped',
                'delivered' => 'Delivered',
                'cancelled' => 'Cancelled',
                'refunded' => 'Refunded',
            ])->required(),
            Forms\Components\TextInput::make('total')->numeric()->prefix('EGP')->disabled(),
            Forms\Components\Textarea::make('notes'),
            Forms\Components\Section::make(__('admin.orders.sections.bosta'))
                ->description(__('admin.orders.sections.bosta_description'))
                ->schema([
                    Forms\Components\Placeholder::make('bosta_status')
                        ->label(__('admin.orders.fields.shipment_status'))
                        ->content(fn (?Order $record): string => $record?->bostaShipment?->status?->label()
                            ?? ($record?->requires_delivery_fulfillment
                                ? __('admin.orders.placeholders.course_gate_pending')
                                : __('admin.orders.placeholders.course_gate_not_bosta'))),
                    Forms\Components\Placeholder::make('bosta_tracking')
                        ->label(__('admin.orders.fields.tracking_number'))
                        ->content(fn (?Order $record): string => $record?->bostaShipment?->tracking_number ?: '—'),
                    Forms\Components\Placeholder::make('bosta_external')
                        ->label(__('admin.orders.fields.external_shipment_id'))
                        ->content(fn (?Order $record): string => $record?->bostaShipment?->external_shipment_id ?: '—'),
                    Forms\Components\Placeholder::make('course_gate')
                        ->label(__('admin.orders.fields.course_unlock'))
                        ->content(fn (?Order $record): string => $record?->fulfilled_at
                            ? __('admin.orders.placeholders.course_gate_active')
                            : ($record?->requires_delivery_fulfillment
                                ? __('admin.orders.placeholders.course_gate_locked')
                                : __('admin.orders.placeholders.course_gate_not_gated'))),
                ])
                ->columns(2)
                ->collapsed(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label(__('admin.common.fields.customer')),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('total')->money('EGP')->sortable(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
