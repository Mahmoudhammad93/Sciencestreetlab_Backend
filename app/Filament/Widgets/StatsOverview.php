<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Products', Product::query()->count())
                ->description('Published kits & courses')
                ->icon('heroicon-o-shopping-bag')
                ->color('primary'),
            Stat::make('Courses', Course::query()->count())
                ->description('LMS courses')
                ->icon('heroicon-o-academic-cap')
                ->color('success'),
            Stat::make('Orders', Order::query()->count())
                ->description('Total orders')
                ->icon('heroicon-o-shopping-cart')
                ->color('warning'),
            Stat::make('Users', User::query()->count())
                ->description('Registered users')
                ->icon('heroicon-o-users')
                ->color('info'),
        ];
    }
}
