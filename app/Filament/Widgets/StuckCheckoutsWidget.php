<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class StuckCheckoutsWidget extends BaseWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Stuck checkouts (awaiting payment)';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->with('user')
                    ->where('status', 'awaiting_payment')
                    ->latest()
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->emptyStateHeading('No stuck checkouts')
            ->emptyStateDescription('All orders have moved past awaiting payment.')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Order')
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->placeholder('Guest'),
                Tables\Columns\TextColumn::make('order_type')
                    ->label('Channel')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'course' ? 'Course plan' : 'Catalog'),
                Tables\Columns\TextColumn::make('total')
                    ->money('EGP'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waiting since')
                    ->since(),
            ]);
    }
}
