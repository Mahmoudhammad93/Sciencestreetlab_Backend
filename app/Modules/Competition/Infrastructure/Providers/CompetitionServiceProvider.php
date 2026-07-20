<?php

declare(strict_types=1);

namespace App\Modules\Competition\Infrastructure\Providers;

use App\Modules\Competition\Application\Services\CompetitionEligibilityService;
use App\Modules\Competition\Application\Services\CompetitionRegistrationService;
use App\Modules\Competition\Application\Services\CompetitionSubmissionService;
use App\Modules\Competition\Application\Services\ParticipantProgressService;
use App\Modules\Competition\Application\Services\SubmissionReviewService;
use App\Modules\Competition\Application\Services\WinnerSelectionService;
use App\Shared\Kernel\ModuleServiceProvider;

final class CompetitionServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Competition';
    }

    public function register(): void
    {
        $this->app->singleton(ParticipantProgressService::class);
        $this->app->singleton(CompetitionEligibilityService::class);
        $this->app->singleton(CompetitionRegistrationService::class);
        $this->app->singleton(CompetitionSubmissionService::class);
        $this->app->singleton(SubmissionReviewService::class);
        $this->app->singleton(WinnerSelectionService::class);
    }
}
