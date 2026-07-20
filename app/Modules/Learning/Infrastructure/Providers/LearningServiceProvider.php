<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Providers;

use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Learning\Application\Listeners\GrantEnrollmentOnOrderPaid;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CourseProgressService;
use App\Modules\Learning\Application\Services\CurriculumService;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Shared\Kernel\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class LearningServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Learning';
    }

    public function register(): void
    {
        $this->app->singleton(CourseProgressService::class);
        $this->app->singleton(CourseAccessService::class);
        $this->app->singleton(CurriculumService::class);
        $this->app->singleton(EnrollUserService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(OrderPaid::class, GrantEnrollmentOnOrderPaid::class);
    }
}
