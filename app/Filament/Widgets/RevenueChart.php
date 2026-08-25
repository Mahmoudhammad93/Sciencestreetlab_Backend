<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class RevenueChart extends ChartWidget
{
    protected static ?string $heading = 'Paid revenue';

    protected static ?string $description = 'Completed payments by day';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 1;

    protected static ?string $maxHeight = '280px';

    public ?string $filter = '14';

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '14' => 'Last 14 days',
            '30' => 'Last 30 days',
        ];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?: 14);
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = Order::query()
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $from)
            ->selectRaw('DATE(paid_at) as day, SUM(total) as total')
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->pluck('total', 'day');

        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->format('M j');
            $values[] = (float) ($rows[$date->toDateString()] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'EGP',
                    'data' => $values,
                    'fill' => true,
                    'borderColor' => '#2828a0',
                    'backgroundColor' => 'rgba(40, 40, 160, 0.12)',
                    'tension' => 0.35,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
