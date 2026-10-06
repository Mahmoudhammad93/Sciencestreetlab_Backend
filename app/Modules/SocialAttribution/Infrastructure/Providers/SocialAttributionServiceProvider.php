<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Infrastructure\Providers;

use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\SocialAttribution\Application\Listeners\RecordPurchaseConversionOnOrderPaid;
use App\Modules\SocialAttribution\Application\Services\AttributionHasher;
use App\Modules\SocialAttribution\Application\Services\AttributionSessionManager;
use App\Modules\SocialAttribution\Application\Services\BotDetector;
use App\Modules\SocialAttribution\Application\Services\DestinationAllowlist;
use App\Modules\SocialAttribution\Application\Services\RecordTrackingClick;
use App\Modules\SocialAttribution\Application\Services\Analytics\ExternalPlatformMetricsProvider;
use App\Modules\SocialAttribution\Application\Services\Analytics\SocialAnalyticsService;
use App\Modules\SocialAttribution\Application\Services\Analytics\UnavailableExternalPlatformMetricsProvider;
use App\Modules\SocialAttribution\Application\Services\SnapshotOrderAttribution;
use App\Modules\SocialAttribution\Application\Services\TrackingRedirectService;
use App\Modules\SocialAttribution\Application\Support\AttributionCookie;
use App\Modules\SocialAttribution\Application\Support\ResolvesAttributionContext;
use App\Shared\Kernel\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

final class SocialAttributionServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'SocialAttribution';
    }

    public function register(): void
    {
        $this->mergeConfigFrom(config_path('social_attribution.php'), 'social_attribution');

        $this->app->singleton(DestinationAllowlist::class);
        $this->app->singleton(BotDetector::class);
        $this->app->singleton(AttributionHasher::class);
        $this->app->singleton(AttributionCookie::class);
        $this->app->singleton(AttributionSessionManager::class);
        $this->app->singleton(RecordTrackingClick::class);
        $this->app->singleton(TrackingRedirectService::class);
        $this->app->singleton(SnapshotOrderAttribution::class);
        $this->app->singleton(ResolvesAttributionContext::class);
        $this->app->singleton(ExternalPlatformMetricsProvider::class, UnavailableExternalPlatformMetricsProvider::class);
        $this->app->singleton(SocialAnalyticsService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Route::middleware('web')
            ->group($this->modulePath('Routes/web.php'));

        Event::listen(OrderPaid::class, RecordPurchaseConversionOnOrderPaid::class);
    }
}
