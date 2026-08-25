<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Widgets\ChartWidget;

class OrdersByStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Orders by status';

    protected static ?string $description = 'Current checkout pipeline';

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 1;

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $counts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [
            'awaiting_payment' => 'Awaiting payment',
            'paid' => 'Paid',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'pending' => 'Pending',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
        ];

        $colors = [
            'awaiting_payment' => '#fcd500',
            'paid' => '#16a34a',
            'processing' => '#2828a0',
            'shipped' => '#0ea5e9',
            'delivered' => '#059669',
            'pending' => '#94a3b8',
            'cancelled' => '#ef4444',
            'refunded' => '#f97316',
        ];

        $usedLabels = [];
        $usedValues = [];
        $usedColors = [];

        foreach ($labels as $status => $label) {
            $value = (int) ($counts[$status] ?? 0);

            if ($value === 0) {
                continue;
            }

            $usedLabels[] = $label;
            $usedValues[] = $value;
            $usedColors[] = $colors[$status];
        }

        if ($usedValues === []) {
            $usedLabels = ['No orders yet'];
            $usedValues = [1];
            $usedColors = ['#e2e8f0'];
        }

        return [
            'datasets' => [
                [
                    'data' => $usedValues,
                    'backgroundColor' => $usedColors,
                ],
            ],
            'labels' => $usedLabels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
