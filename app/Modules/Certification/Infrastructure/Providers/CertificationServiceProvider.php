<?php

declare(strict_types=1);

namespace App\Modules\Certification\Infrastructure\Providers;

use App\Modules\Certification\Application\Listeners\IssueCertificateOnCourseCompleted;
use App\Modules\Certification\Application\Services\CertificateIssuanceService;
use App\Modules\Certification\Application\Services\CertificateNumberGenerator;
use App\Modules\Certification\Application\Services\CertificatePdfGenerator;
use App\Modules\Learning\Domain\Events\CourseCompleted;
use App\Shared\Kernel\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class CertificationServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Certification';
    }

    public function register(): void
    {
        $this->app->singleton(CertificateNumberGenerator::class);
        $this->app->singleton(CertificateIssuanceService::class);
        $this->app->singleton(CertificatePdfGenerator::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(CourseCompleted::class, IssueCertificateOnCourseCompleted::class);
    }
}
