<?php

declare(strict_types=1);

namespace App\Modules\Mobile\Infrastructure\Providers;

use App\Modules\Mobile\Application\Services\MobileHomeService;
use App\Modules\Mobile\Application\Services\MobileLearningDashboardService;
use App\Shared\Kernel\ModuleServiceProvider;

final class MobileServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Mobile';
    }

    public function register(): void
    {
        $this->app->singleton(MobileHomeService::class);
        $this->app->singleton(MobileLearningDashboardService::class);
    }
}
