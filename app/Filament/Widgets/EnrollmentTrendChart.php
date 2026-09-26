<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class EnrollmentTrendChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '280px';

    public ?string $filter = '14';

    public function getHeading(): ?string
    {
        return __('admin.widgets.charts.enrollments.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.widgets.charts.enrollments.description');
    }

    protected function getFilters(): ?array
    {
        return [
            '7' => __('admin.widgets.charts.filters.last_7_days'),
            '14' => __('admin.widgets.charts.filters.last_14_days'),
            '30' => __('admin.widgets.charts.filters.last_30_days'),
        ];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?: 14);
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = Enrollment::query()
            ->whereNotNull('enrolled_at')
            ->where('enrolled_at', '>=', $from)
            ->selectRaw('DATE(enrolled_at) as day, COUNT(*) as total')
            ->groupBy(DB::raw('DATE(enrolled_at)'))
            ->pluck('total', 'day');

        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->format('M j');
            $values[] = (int) ($rows[$date->toDateString()] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => __('admin.widgets.charts.enrollments.dataset_label'),
                'data' => $values,
                'fill' => true,
                'borderColor' => '#16a34a',
                'backgroundColor' => 'rgba(22, 163, 74, 0.15)',
                'tension' => 0.35,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
