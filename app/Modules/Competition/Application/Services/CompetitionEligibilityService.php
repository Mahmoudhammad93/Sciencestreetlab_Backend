<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;

final class CompetitionEligibilityService
{
    public function __construct(
        private readonly QuizAttemptService $quizAttempts,
    ) {}

    /**
     * Authoritative participation decision for an authenticated user.
     *
     * @return array{
     *     authenticated: bool,
     *     user_id: int,
     *     competition_active: bool,
     *     competition_status: string,
     *     registered: bool,
     *     participant_id: int|null,
     *     eligible: bool,
     *     eligibility_reason: string|null,
     *     prerequisite_satisfied: bool,
     *     can_register: bool,
     *     state: string
     * }
     */
    public function participationStatus(User $user, Competition $competition): array
    {
        $participant = CompetitionParticipant::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $user->id)
            ->first();

        $registered = $participant !== null;
        $competitionActive = $competition->isActive();
        $prerequisiteSatisfied = $this->hasPrerequisiteEnrollment($user, $competition);
        $eligibility = $this->canParticipate($user, $competition);

        $canRegister = ! $registered && $eligibility['eligible'];

        $state = match (true) {
            $registered => 'REGISTERED_PARTICIPANT',
            ! $competitionActive => 'COMPETITION_INACTIVE',
            $canRegister => 'ELIGIBLE_TO_REGISTER',
            ! $prerequisiteSatisfied,
            ($eligibility['reason'] ?? null) === 'quizzes_not_passed' => 'INELIGIBLE',
            default => 'AUTHENTICATED_NOT_REGISTERED',
        };

        return [
            'authenticated' => true,
            'user_id' => (int) $user->id,
            'competition_active' => $competitionActive,
            'competition_status' => (string) $competition->status,
            'registered' => $registered,
            'participant_id' => $participant?->id,
            'eligible' => $eligibility['eligible'],
            'eligibility_reason' => $eligibility['reason'],
            'prerequisite_satisfied' => $prerequisiteSatisfied,
            'can_register' => $canRegister,
            'state' => $state,
        ];
    }

    /** @return array{eligible: bool, reason: string|null} */
    public function canParticipate(User $user, Competition $competition): array
    {
        if (! $competition->isActive()) {
            return ['eligible' => false, 'reason' => 'competition_not_active'];
        }

        $enrollment = $this->prerequisiteEnrollment($user, $competition);

        if ($enrollment === null) {
            return ['eligible' => false, 'reason' => 'course_not_completed'];
        }

        $competition->loadMissing('prerequisiteCourse');

        if (! $this->allRequiredQuizzesPassed($user, $competition->prerequisiteCourse, $enrollment)) {
            return ['eligible' => false, 'reason' => 'quizzes_not_passed'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    public function hasPrerequisiteEnrollment(User $user, Competition $competition): bool
    {
        return $this->prerequisiteEnrollment($user, $competition) !== null;
    }

    private function prerequisiteEnrollment(User $user, Competition $competition): ?Enrollment
    {
        return Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $competition->prerequisite_course_id)
            ->whereIn('status', [
                EnrollmentStatus::Active->value,
                EnrollmentStatus::Completed->value,
            ])
            ->first();
    }

    private function allRequiredQuizzesPassed(User $user, Course $course, Enrollment $enrollment): bool
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
            if (! $this->quizAttempts->hasPassed($user, $quiz, $enrollment)) {
                return false;
            }
        }

        return true;
    }
}
