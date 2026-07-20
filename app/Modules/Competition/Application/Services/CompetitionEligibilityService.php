<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;

final class CompetitionEligibilityService
{
    public function __construct(
        private readonly QuizAttemptService $quizAttempts,
    ) {}

    /** @return array{eligible: bool, reason: string|null} */
    public function canParticipate(User $user, Competition $competition): array
    {
        if (! $competition->isActive()) {
            return ['eligible' => false, 'reason' => 'competition_not_active'];
        }

        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $competition->prerequisite_course_id)
            ->where('status', EnrollmentStatus::Completed)
            ->first();

        if (! $enrollment) {
            return ['eligible' => false, 'reason' => 'course_not_completed'];
        }

        $competition->loadMissing('prerequisiteCourse');

        if (! $this->allRequiredQuizzesPassed($user, $competition->prerequisiteCourse)) {
            return ['eligible' => false, 'reason' => 'quizzes_not_passed'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    private function allRequiredQuizzesPassed(User $user, Course $course): bool
    {
        $lessonIds = $course->lessons()->where('is_published', true)->pluck('id');

        $requiredQuizzes = Quiz::query()
            ->where('quizable_type', Lesson::class)
            ->whereIn('quizable_id', $lessonIds)
            ->where('is_required', true)
            ->get();

        if ($requiredQuizzes->isEmpty()) {
            return true;
        }

        foreach ($requiredQuizzes as $quiz) {
            if (! $this->quizAttempts->hasPassed($user, $quiz)) {
                return false;
            }
        }

        return true;
    }
}
