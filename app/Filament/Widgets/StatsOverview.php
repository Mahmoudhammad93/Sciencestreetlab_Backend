<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\CompetitionSubmissionResource;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $paidRevenue = (float) Order::query()
            ->whereNotNull('paid_at')
            ->sum('total');

        $awaitingPayment = Order::query()
            ->where('status', 'awaiting_payment')
            ->count();

        $ordersThisMonth = Order::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $usersThisMonth = User::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $activeEnrollments = Enrollment::query()
            ->where('status', EnrollmentStatus::Active)
            ->count();

        $pendingReviews = CompetitionSubmission::query()
            ->where('status', SubmissionStatus::Pending)
            ->count();

        $certificateCount = Certificate::query()->count();
        $productPublished = Product::query()->where('status', 'published')->count();
        $productTotal = Product::query()->count();
        $coursePublished = Course::query()->where('is_published', true)->count();
        $courseTotal = Course::query()->count();
        $orderTotal = Order::query()->count();
        $paidOrderCount = Order::query()->whereNotNull('paid_at')->count();
        $userTotal = User::query()->count();

        return [
            Stat::make(__('admin.widgets.stats.revenue.title'), number_format($paidRevenue, 0).' EGP')
                ->description(__('admin.widgets.stats.revenue.description'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($this->dailyPaidTotals(7))
                ->url(OrderResource::getUrl('index'))
                ->color('success'),
            Stat::make(__('admin.widgets.stats.awaiting_payment.title'), $awaitingPayment)
                ->description($ordersThisMonth.' '.__('admin.widgets.stats.awaiting_payment.description'))
                ->descriptionIcon('heroicon-m-clock')
                ->url(OrderResource::getUrl('index'))
                ->color('warning'),
            Stat::make(__('admin.widgets.stats.active_enrollments.title'), $activeEnrollments)
                ->description($certificateCount.' '.__('admin.widgets.stats.active_enrollments.description'))
                ->descriptionIcon('heroicon-m-academic-cap')
                ->url(CourseResource::getUrl('index'))
                ->color('primary'),
            Stat::make(__('admin.widgets.stats.pending_reviews.title'), $pendingReviews)
                ->description(__('admin.widgets.stats.pending_reviews.description'))
                ->descriptionIcon('heroicon-m-camera')
                ->url(CompetitionSubmissionResource::getUrl('index'))
                ->color($pendingReviews > 0 ? 'danger' : 'success'),
            Stat::make(__('admin.widgets.stats.products.title'), $productPublished)
                ->description($productTotal.' '.__('admin.widgets.stats.products.description'))
                ->icon('heroicon-o-shopping-bag')
                ->url(ProductResource::getUrl('index'))
                ->color('primary'),
            Stat::make(__('admin.widgets.stats.courses.title'), $coursePublished)
                ->description($courseTotal.' '.__('admin.widgets.stats.courses.description'))
                ->icon('heroicon-o-academic-cap')
                ->url(CourseResource::getUrl('index'))
                ->color('success'),
            Stat::make(__('admin.widgets.stats.orders.title'), $orderTotal)
                ->description($paidOrderCount.' '.__('admin.widgets.stats.orders.description'))
                ->icon('heroicon-o-shopping-cart')
                ->chart($this->dailyOrderCounts(7))
                ->url(OrderResource::getUrl('index'))
                ->color('warning'),
            Stat::make(__('admin.widgets.stats.users.title'), $userTotal)
                ->description('+'.$usersThisMonth.' '.__('admin.widgets.stats.users.description'))
                ->descriptionIcon('heroicon-m-users')
                ->url(UserResource::getUrl('index'))
                ->color('info'),
        ];
    }

    /**
     * @return list<float>
     */
    private function dailyPaidTotals(int $days): array
    {
        $rows = Order::query()
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(paid_at) as day, SUM(total) as total')
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->pluck('total', 'day');

        return $this->fillDailySeries($days, $rows);
    }

    /**
     * @return list<int>
     */
    private function dailyOrderCounts(int $days): array
    {
        $rows = Order::query()
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'day');

        return array_map(intval(...), $this->fillDailySeries($days, $rows));
    }

    /**
     * @param  \Illuminate\Support\Collection<string, mixed>  $rows
     * @return list<float>
     */
    private function fillDailySeries(int $days, $rows): array
    {
        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $key = now()->subDays($i)->toDateString();
            $series[] = (float) ($rows[$key] ?? 0);
        }

        return $series;
    }
}
