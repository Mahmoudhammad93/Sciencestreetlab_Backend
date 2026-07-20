<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Application\Listeners;

use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Competition\Domain\Events\CompetitionSubmissionApproved;
use App\Modules\Gamification\Application\Services\AchievementEvaluationService;
use App\Modules\Learning\Domain\Events\CourseCompleted;

final class EvaluateAchievementsOnEvents
{
    public function __construct(
        private readonly AchievementEvaluationService $evaluation,
    ) {}

    public function handleCourseCompleted(CourseCompleted $event): void
    {
        $this->evaluation->evaluate($event);
    }

    public function handleQuizPassed(QuizPassed $event): void
    {
        $this->evaluation->evaluate($event);
    }

    public function handleSubmissionApproved(CompetitionSubmissionApproved $event): void
    {
        $this->evaluation->evaluate($event);
    }
}
