<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Infrastructure\Providers;

use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Competition\Domain\Events\CompetitionSubmissionApproved;
use App\Modules\Gamification\Application\Listeners\EvaluateAchievementsOnEvents;
use App\Modules\Gamification\Application\Services\AchievementEvaluationService;
use App\Modules\Gamification\Application\Services\EventConditionMatcher;
use App\Modules\Gamification\Application\Services\PointsService;
use App\Modules\Learning\Domain\Events\CourseCompleted;
use App\Shared\Kernel\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class GamificationServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Gamification';
    }

    public function register(): void
    {
        $this->app->singleton(EventConditionMatcher::class);
        $this->app->singleton(PointsService::class);
        $this->app->singleton(AchievementEvaluationService::class);
        $this->app->singleton(EvaluateAchievementsOnEvents::class);
    }

    public function boot(): void
    {
        parent::boot();

        $listener = EvaluateAchievementsOnEvents::class;

        Event::listen(CourseCompleted::class, [$listener, 'handleCourseCompleted']);
        Event::listen(QuizPassed::class, [$listener, 'handleQuizPassed']);
        Event::listen(CompetitionSubmissionApproved::class, [$listener, 'handleSubmissionApproved']);
    }
}
