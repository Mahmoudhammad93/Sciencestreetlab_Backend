<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Competition\Domain\Events\CompetitionSubmissionApproved;
use App\Modules\Gamification\Domain\Events\AchievementUnlocked;
use App\Modules\Gamification\Infrastructure\Persistence\Models\Achievement;
use App\Modules\Gamification\Infrastructure\Persistence\Models\AchievementRule;
use App\Modules\Gamification\Infrastructure\Persistence\Models\UserAchievement;
use App\Modules\Learning\Domain\Events\CourseCompleted;
use Illuminate\Support\Facades\DB;

final class AchievementEvaluationService
{
    public function __construct(
        private readonly EventConditionMatcher $matcher,
        private readonly PointsService $points,
    ) {}

    public function evaluate(object $event): void
    {
        $user = $this->resolveUser($event);

        if (! $user) {
            return;
        }

        $rules = AchievementRule::query()
            ->where('trigger_event', $event::class)
            ->whereHas('achievement', fn ($q) => $q->where('is_active', true))
            ->with('achievement')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matcher->matches($rule->conditions ?? [], $event, $user)) {
                $this->award($user, $rule->achievement, $event);
            }
        }
    }

    private function award(User $user, Achievement $achievement, object $event): void
    {
        $exists = UserAchievement::query()
            ->where('user_id', $user->id)
            ->where('achievement_id', $achievement->id)
            ->exists();

        if ($exists) {
            return;
        }

        DB::transaction(function () use ($user, $achievement, $event): void {
            UserAchievement::query()->create([
                'user_id' => $user->id,
                'achievement_id' => $achievement->id,
                'awarded_at' => now(),
                'metadata' => ['trigger' => $event::class],
            ]);

            if ($achievement->points > 0) {
                $this->points->add(
                    $user,
                    $achievement->points,
                    Achievement::class,
                    $achievement->id,
                    "Achievement: {$achievement->slug}"
                );
            }

            event(new AchievementUnlocked($user, $achievement));
        });
    }

    private function resolveUser(object $event): ?User
    {
        if ($event instanceof CourseCompleted) {
            $event->enrollment->loadMissing('user');

            return $event->enrollment->user;
        }

        if ($event instanceof QuizPassed && $event->attempt instanceof QuizAttempt) {
            $event->attempt->loadMissing('user');

            return $event->attempt->user;
        }

        if ($event instanceof CompetitionSubmissionApproved) {
            $event->submission->loadMissing('participant.user');

            return $event->submission->participant->user;
        }

        return null;
    }
}
