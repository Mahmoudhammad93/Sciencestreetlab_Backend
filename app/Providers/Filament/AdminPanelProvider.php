<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CeoBusinessStats;
use App\Filament\Widgets\EnrollmentTrendChart;
use App\Filament\Widgets\LatestEnrollmentsWidget;
use App\Filament\Widgets\OrdersByStatusChart;
use App\Filament\Widgets\PendingReviewsWidget;
use App\Filament\Widgets\RecentOrdersWidget;
use App\Filament\Widgets\RevenueByChannelChart;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\StuckCheckoutsWidget;
use App\Filament\Widgets\TopCoursesWidget;
use App\Services\SiteSettings;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $settings = SiteSettings::get();
        $primary = SiteSettings::normalizeHex((string) $settings['admin_primary_color'], '#2828a0');
        $accent = SiteSettings::normalizeHex((string) $settings['admin_accent_color'], '#fcd500');
        $logo = is_string($settings['logo_url'] ?? null) && $settings['logo_url'] !== ''
            ? $settings['logo_url']
            : null;

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName((string) $settings['admin_brand_name'])
            ->colors([
                'primary' => Color::hex($primary),
                'warning' => Color::hex($accent),
            ])
            ->maxContentWidth($this->layoutWidth((string) $settings['admin_layout']))
            ->defaultThemeMode($this->themeMode((string) $settings['admin_theme_mode']))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverview::class,
                CeoBusinessStats::class,
                RevenueChart::class,
                RevenueByChannelChart::class,
                EnrollmentTrendChart::class,
                OrdersByStatusChart::class,
                TopCoursesWidget::class,
                StuckCheckoutsWidget::class,
                RecentOrdersWidget::class,
                LatestEnrollmentsWidget::class,
                PendingReviewsWidget::class,
            ])
            ->navigationGroups([
                'Catalog',
                'Commerce',
                'Learning',
                'Competition',
                'Identity',
                'Content',
                'Settings',
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        if ($logo) {
            $panel->brandLogo($logo)->brandLogoHeight('2.5rem');
        }

        if ($settings['admin_sidebar_collapsible']) {
            $panel->sidebarCollapsibleOnDesktop();
        }

        return $panel;
    }

    private function layoutWidth(string $layout): MaxWidth
    {
        return match ($layout) {
            'compact' => MaxWidth::FiveExtraLarge,
            'fluid' => MaxWidth::Full,
            default => MaxWidth::SevenExtraLarge,
        };
    }

    private function themeMode(string $mode): ThemeMode
    {
        return match ($mode) {
            'light' => ThemeMode::Light,
            'dark' => ThemeMode::Dark,
            default => ThemeMode::System,
        };
    }
}
