<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Widgets\ChartWidget;

class RevenueByChannelChart extends ChartWidget
{
    protected static ?string $heading = 'Revenue by channel';

    protected static ?string $description = 'Paid course plans vs catalog / kit sales';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $course = (float) Order::query()
            ->whereNotNull('paid_at')
            ->where('order_type', 'course')
            ->sum('total');

        $catalog = (float) Order::query()
            ->whereNotNull('paid_at')
            ->where(function ($q): void {
                $q->whereNull('order_type')->orWhere('order_type', '!=', 'course');
            })
            ->sum('total');

        if ($course <= 0 && $catalog <= 0) {
            return [
                'datasets' => [[
                    'data' => [1],
                    'backgroundColor' => ['#e2e8f0'],
                ]],
                'labels' => ['No paid revenue yet'],
            ];
        }

        return [
            'datasets' => [[
                'data' => [$course, $catalog],
                'backgroundColor' => ['#2828a0', '#fcd500'],
            ]],
            'labels' => [
                'Course plans ('.number_format($course, 0).' EGP)',
                'Catalog / kits ('.number_format($catalog, 0).' EGP)',
            ],
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
