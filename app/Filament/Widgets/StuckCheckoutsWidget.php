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

    public function getHeading(): ?string
    {
        return __('admin.widgets.stuck_checkouts.heading');
    }

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
            ->emptyStateHeading(__('admin.widgets.stuck_checkouts.empty_heading'))
            ->emptyStateDescription(__('admin.widgets.stuck_checkouts.empty_description'))
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('admin.widgets.stuck_checkouts.table.order'))
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.widgets.stuck_checkouts.table.customer'))
                    ->placeholder(__('admin.widgets.stuck_checkouts.table.guest')),
                Tables\Columns\TextColumn::make('order_type')
                    ->label(__('admin.widgets.stuck_checkouts.table.channel'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'course'
                        ? (string) __('admin.widgets.stuck_checkouts.channels.course_plan')
                        : (string) __('admin.widgets.stuck_checkouts.channels.catalog')),
                Tables\Columns\TextColumn::make('total')
                    ->money('EGP'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.widgets.stuck_checkouts.table.waiting_since'))
                    ->since(),
            ]);
    }
}
