<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Providers;

use App\Modules\SocialCommerce\Application\Events\ProductSalesChannelSyncRequested;
use App\Modules\SocialCommerce\Application\Listeners\QueueConnectedChannelSync;
use App\Modules\SocialCommerce\Application\Services\ChannelHealthService;
use App\Modules\SocialCommerce\Application\Services\GoogleMerchantSetupService;
use App\Modules\SocialCommerce\Application\Services\HumanErrorMapper;
use App\Modules\SocialCommerce\Application\Services\ProductChannelMapper;
use App\Modules\SocialCommerce\Application\Services\ProductReadinessService;
use App\Modules\SocialCommerce\Application\Services\SalesChannelManager;
use App\Modules\SocialCommerce\Infrastructure\Google\GoogleMerchantApiClient;
use App\Modules\SocialCommerce\Infrastructure\Google\GoogleServiceAccountTokenProvider;
use App\Modules\SocialCommerce\Infrastructure\Google\ServiceAccountCredentialParser;
use App\Modules\SocialCommerce\Infrastructure\Providers\Adapters\GoogleMerchantProvider;
use App\Modules\SocialCommerce\Infrastructure\Providers\Adapters\YouTubeShoppingProvider;
use App\Shared\Kernel\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class SocialCommerceServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'SocialCommerce';
    }

    public function register(): void
    {
        $this->mergeConfigFrom(config_path('sales_channels.php'), 'sales_channels');

        $this->app->singleton(HumanErrorMapper::class);
        $this->app->singleton(ProductChannelMapper::class);
        $this->app->singleton(ProductReadinessService::class);
        $this->app->singleton(ChannelHealthService::class);
        $this->app->singleton(GoogleServiceAccountTokenProvider::class);
        $this->app->singleton(GoogleMerchantApiClient::class);
        $this->app->singleton(ServiceAccountCredentialParser::class);
        $this->app->singleton(GoogleMerchantProvider::class);
        $this->app->singleton(YouTubeShoppingProvider::class);
        $this->app->singleton(GoogleMerchantSetupService::class);

        $this->app->singleton(SalesChannelManager::class, function ($app): SalesChannelManager {
            return new SalesChannelManager(
                providers: [
                    'google_merchant' => $app->make(GoogleMerchantProvider::class),
                    'youtube_shopping' => $app->make(YouTubeShoppingProvider::class),
                ],
                mapper: $app->make(ProductChannelMapper::class),
                readiness: $app->make(ProductReadinessService::class),
                health: $app->make(ChannelHealthService::class),
            );
        });
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(
            ProductSalesChannelSyncRequested::class,
            QueueConnectedChannelSync::class,
        );
    }
}
