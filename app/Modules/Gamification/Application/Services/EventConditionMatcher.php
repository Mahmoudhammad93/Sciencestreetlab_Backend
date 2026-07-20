<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Competition\Domain\Events\CompetitionSubmissionApproved;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Learning\Domain\Events\CourseCompleted;

final class EventConditionMatcher
{
    public function matches(array $conditions, object $event, User $user): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $key => $value) {
            if (! $this->matchCondition($key, $value, $event, $user)) {
                return false;
            }
        }

        return true;
    }

    private function matchCondition(string $key, mixed $value, object $event, User $user): bool
    {
        return match ($key) {
            'course_slug' => $this->matchCourseSlug($value, $event),
            'passed_count' => $this->matchPassedCount($value, $user),
            'approved_count' => $this->matchApprovedCount($value, $user),
            default => true,
        };
    }

    private function matchCourseSlug(mixed $slug, object $event): bool
    {
        if (! $event instanceof CourseCompleted) {
            return false;
        }

        $event->enrollment->loadMissing('course');

        return $event->enrollment->course->slug === $slug;
    }

    /** @param  array{gte?: int}  $rule */
    private function matchPassedCount(array $rule, User $user): bool
    {
        $count = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->where('passed', true)
            ->count();

        if (isset($rule['gte'])) {
            return $count >= (int) $rule['gte'];
        }

        return true;
    }

    /** @param  array{gte?: int}  $rule */
    private function matchApprovedCount(array $rule, User $user): bool
    {
        $count = CompetitionParticipant::query()
            ->where('user_id', $user->id)
            ->sum('approved_count');

        if (isset($rule['gte'])) {
            return $count >= (int) $rule['gte'];
        }

        return true;
    }
}
