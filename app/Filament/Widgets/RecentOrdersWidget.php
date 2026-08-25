<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentOrdersWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 1;

    protected static ?string $heading = 'Recent orders';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->with('user')
                    ->latest()
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Order')
                    ->searchable()
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->placeholder('Guest'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid', 'delivered' => 'success',
                        'awaiting_payment', 'pending' => 'warning',
                        'cancelled', 'refunded' => 'danger',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('total')
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record]))
                    ->icon('heroicon-m-arrow-top-right-on-square'),
            ]);
    }
}
