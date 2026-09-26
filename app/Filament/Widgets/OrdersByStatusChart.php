<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Widgets\ChartWidget;

class OrdersByStatusChart extends ChartWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '280px';

    public function getHeading(): ?string
    {
        return __('admin.widgets.charts.orders_by_status.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.widgets.charts.orders_by_status.description');
    }

    protected function getData(): array
    {
        $counts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [
            'awaiting_payment' => __('admin.widgets.charts.orders_by_status.labels.awaiting_payment'),
            'paid' => __('admin.widgets.charts.orders_by_status.labels.paid'),
            'processing' => __('admin.widgets.charts.orders_by_status.labels.processing'),
            'shipped' => __('admin.widgets.charts.orders_by_status.labels.shipped'),
            'delivered' => __('admin.widgets.charts.orders_by_status.labels.delivered'),
            'pending' => __('admin.widgets.charts.orders_by_status.labels.pending'),
            'cancelled' => __('admin.widgets.charts.orders_by_status.labels.cancelled'),
            'refunded' => __('admin.widgets.charts.orders_by_status.labels.refunded'),
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
            $usedLabels = [__('admin.widgets.charts.orders_by_status.labels.empty')];
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

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
