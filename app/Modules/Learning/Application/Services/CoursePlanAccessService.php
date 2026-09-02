<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Eloquent\Model;

final class CoursePlanAccessService
{
    public function isEnrollmentActive(Enrollment $enrollment): bool
    {
        if (! in_array($enrollment->status, [EnrollmentStatus::Active, EnrollmentStatus::Completed], true)) {
            return false;
        }

        if ($enrollment->expires_at !== null && $enrollment->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function usesPlanEntitlements(Enrollment $enrollment): bool
    {
        return $enrollment->course_plan_id !== null
            && $enrollment->entitlements()->exists();
    }

    public function canAccessCourse(Enrollment $enrollment, Course $course): bool
    {
        if ($enrollment->course_id !== $course->id || ! $this->isEnrollmentActive($enrollment)) {
            return false;
        }

        if (! $this->usesPlanEntitlements($enrollment)) {
            return true;
        }

        return $enrollment->entitlements()
            ->where(function ($query): void {
                $query->where('entitleable_type', Lesson::class)
                    ->orWhere('entitleable_type', Topic::class)
                    ->orWhere('entitleable_type', Quiz::class)
                    ->orWhere('entitleable_type', InteractiveActivity::class);
            })
            ->exists();
    }

    public function canAccessLesson(Enrollment $enrollment, Lesson $lesson): bool
    {
        if ($lesson->course_id !== $enrollment->course_id || ! $this->isEnrollmentActive($enrollment)) {
            return false;
        }

        if (! $this->usesPlanEntitlements($enrollment)) {
            return true;
        }

        return $this->hasEntitlement($enrollment, Lesson::class, $lesson->id);
    }

    public function canAccessTopic(Enrollment $enrollment, Topic $topic): bool
    {
        $lesson = $topic->lesson;

        if ($lesson === null || ! $this->isEnrollmentActive($enrollment)) {
            return false;
        }

        if (! $this->usesPlanEntitlements($enrollment)) {
            return true;
        }

        return $this->hasEntitlement($enrollment, Topic::class, $topic->id)
            || $this->hasEntitlement($enrollment, Lesson::class, $lesson->id);
    }

    public function canAccessQuiz(Enrollment $enrollment, Quiz $quiz): bool
    {
        if (! $this->isEnrollmentActive($enrollment)) {
            return false;
        }

        if (! $this->usesPlanEntitlements($enrollment)) {
            return true;
        }

        return $this->hasEntitlement($enrollment, Quiz::class, $quiz->id);
    }

    public function canAccessInteractiveActivity(Enrollment $enrollment, InteractiveActivity $activity): bool
    {
        if (! $this->isEnrollmentActive($enrollment)) {
            return false;
        }

        if (! $this->usesPlanEntitlements($enrollment)) {
            return true;
        }

        return $this->hasEntitlement($enrollment, InteractiveActivity::class, $activity->id);
    }

    public function canAccessCertificate(Enrollment $enrollment): bool
    {
        if (! $this->isEnrollmentActive($enrollment)) {
            return false;
        }

        if (! $this->usesPlanEntitlements($enrollment)) {
            return true;
        }

        return (bool) $enrollment->grant_certificate;
    }

    public function maxQuizAttempts(Enrollment $enrollment, Quiz $quiz): ?int
    {
        if ($this->usesPlanEntitlements($enrollment) && $enrollment->coursePlan?->max_quiz_attempts !== null) {
            return (int) $enrollment->coursePlan->max_quiz_attempts;
        }

        return $quiz->max_attempts !== null ? (int) $quiz->max_attempts : null;
    }

    public function accessDeniedReason(Enrollment $enrollment, Model $resource): ?string
    {
        if (! $this->isEnrollmentActive($enrollment)) {
            return 'Your enrollment is not active or has expired.';
        }

        return match (true) {
            $resource instanceof Lesson && ! $this->canAccessLesson($enrollment, $resource) => 'This lesson is not included in your plan.',
            $resource instanceof Topic && ! $this->canAccessTopic($enrollment, $resource) => 'This topic is not included in your plan.',
            $resource instanceof Quiz && ! $this->canAccessQuiz($enrollment, $resource) => 'This quiz is not included in your plan.',
            $resource instanceof InteractiveActivity && ! $this->canAccessInteractiveActivity($enrollment, $resource) => 'This interactive activity is not included in your plan.',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function accessSummary(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['coursePlan', 'entitlements']);

        $usesPlan = $this->usesPlanEntitlements($enrollment);

        return [
            'enrolled' => true,
            'active' => $this->isEnrollmentActive($enrollment),
            'uses_plan' => $usesPlan,
            'enrollment' => [
                'id' => $enrollment->id,
                'status' => $enrollment->status->value,
                'started_at' => $enrollment->started_at?->toIso8601String(),
                'expires_at' => $enrollment->expires_at?->toIso8601String(),
            ],
            'plan' => $enrollment->coursePlan ? [
                'id' => $enrollment->coursePlan->id,
                'name' => $enrollment->coursePlan->getTranslation('name', app()->getLocale()),
            ] : null,
            'access' => [
                'course' => $this->canAccessCourse($enrollment, $enrollment->course),
                'certificate' => $this->canAccessCertificate($enrollment),
                'lesson_ids' => $usesPlan
                    ? $enrollment->entitlements()->where('entitleable_type', Lesson::class)->pluck('entitleable_id')->values()->all()
                    : null,
                'topic_ids' => $usesPlan
                    ? $enrollment->entitlements()->where('entitleable_type', Topic::class)->pluck('entitleable_id')->values()->all()
                    : null,
                'quiz_ids' => $usesPlan
                    ? $enrollment->entitlements()->where('entitleable_type', Quiz::class)->pluck('entitleable_id')->values()->all()
                    : null,
                'interactive_activity_ids' => $usesPlan
                    ? $enrollment->entitlements()->where('entitleable_type', InteractiveActivity::class)->pluck('entitleable_id')->values()->all()
                    : null,
            ],
        ];
    }

    private function hasEntitlement(Enrollment $enrollment, string $type, int $id): bool
    {
        return $enrollment->entitlements()
            ->where('entitleable_type', $type)
            ->where('entitleable_id', $id)
            ->exists();
    }
}
