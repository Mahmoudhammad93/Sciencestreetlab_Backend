<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizOfficialScore;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Second KPI row aimed at CEO / leadership: conversion, growth, LMS health.
 */
class CeoBusinessStats extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $totalOrders = max(1, Order::query()->count());
        $paidOrders = Order::query()->whereNotNull('paid_at')->count();
        $conversion = round(($paidOrders / $totalOrders) * 100, 1);

        $awaiting = Order::query()->where('status', 'awaiting_payment')->count();
        $paymentSuccessRate = $this->paymentSuccessRate();

        $revenueThisMonth = (float) Order::query()
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->startOfMonth())
            ->sum('total');

        $revenueLastMonth = (float) Order::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ])
            ->sum('total');

        $mom = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : ($revenueThisMonth > 0 ? 100.0 : 0.0);

        $aov = $paidOrders > 0
            ? (float) Order::query()->whereNotNull('paid_at')->avg('total')
            : 0.0;

        $courseRevenue = (float) Order::query()
            ->whereNotNull('paid_at')
            ->where('order_type', 'course')
            ->sum('total');

        $productRevenue = (float) Order::query()
            ->whereNotNull('paid_at')
            ->where(function ($q): void {
                $q->whereNull('order_type')->orWhere('order_type', '!=', 'course');
            })
            ->sum('total');

        $newEnrollmentsMonth = Enrollment::query()
            ->where('enrolled_at', '>=', now()->startOfMonth())
            ->count();

        $activeEnrollments = Enrollment::query()
            ->where('status', EnrollmentStatus::Active)
            ->count();

        $quizAttempts = QuizAttempt::query()->count();
        $officialScores = QuizOfficialScore::query()->count();

        $completedPayments = Payment::query()->where('status', 'completed')->count();
        $failedPayments = Payment::query()->whereIn('status', ['failed', 'cancelled'])->count();

        $paidCourseOrders = Order::query()->where('order_type', 'course')->whereNotNull('paid_at')->count();

        return [
            Stat::make(__('admin.widgets.ceo.checkout_conversion.title'), $conversion.'%')
                ->description($paidOrders.' paid / '.$totalOrders.' '.__('admin.widgets.ceo.checkout_conversion.description').' · '.$awaiting)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($conversion >= 30 ? 'success' : ($conversion >= 15 ? 'warning' : 'danger')),
            Stat::make(__('admin.widgets.ceo.revenue_this_month.title'), number_format($revenueThisMonth, 0).' EGP')
                ->description(($mom >= 0 ? '+' : '').$mom.'% '.__('admin.widgets.ceo.revenue_this_month.description').' ('.number_format($revenueLastMonth, 0).' EGP)')
                ->descriptionIcon($mom >= 0 ? 'heroicon-m-arrow-up-right' : 'heroicon-m-arrow-down-right')
                ->color($mom >= 0 ? 'success' : 'danger'),
            Stat::make(__('admin.widgets.ceo.avg_paid_order.title'), number_format($aov, 0).' EGP')
                ->description(__('admin.widgets.ceo.avg_paid_order.description').' · '.number_format($courseRevenue, 0).' · '.number_format($productRevenue, 0).' EGP')
                ->descriptionIcon('heroicon-m-calculator')
                ->color('primary'),
            Stat::make(__('admin.widgets.ceo.payment_health.title'), $paymentSuccessRate.'% success')
                ->description($completedPayments.' '.__('admin.widgets.ceo.payment_health.description').' · '.$failedPayments)
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($paymentSuccessRate >= 70 ? 'success' : 'warning'),
            Stat::make(__('admin.widgets.ceo.new_enrollments_mtd.title'), $newEnrollmentsMonth)
                ->description($activeEnrollments.' '.__('admin.widgets.ceo.new_enrollments_mtd.description'))
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('info'),
            Stat::make(__('admin.widgets.ceo.course_plan_revenue.title'), number_format($courseRevenue, 0).' EGP')
                ->description($paidCourseOrders.' '.__('admin.widgets.ceo.course_plan_revenue.description'))
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('success'),
            Stat::make(__('admin.widgets.ceo.learning_engagement.title'), $quizAttempts.' quiz attempts')
                ->description($officialScores.' '.__('admin.widgets.ceo.learning_engagement.description'))
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('primary'),
            Stat::make(__('admin.widgets.ceo.stuck_checkouts.title'), $awaiting)
                ->description(__('admin.widgets.ceo.stuck_checkouts.description'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($awaiting > 0 ? 'warning' : 'success'),
        ];
    }

    private function paymentSuccessRate(): float
    {
        $total = Payment::query()->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = Payment::query()->where('status', 'completed')->count();

        return round(($completed / $total) * 100, 1);
    }
}
