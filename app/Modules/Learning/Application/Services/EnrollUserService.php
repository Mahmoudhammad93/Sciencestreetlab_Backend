<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Models\User;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EnrollUserService
{
    public function __construct(
        private readonly CoursePlanEntitlementSyncService $entitlementSync,
    ) {}

    public function enroll(User $user, Course $course, ?int $orderItemId = null, ?CoursePlan $plan = null): Enrollment
    {
        if ($plan !== null && $plan->course_id !== $course->id) {
            throw new DomainException('Course plan does not belong to this course.');
        }

        if ($plan !== null && ! $plan->is_active) {
            throw new DomainException('Course plan is not active.');
        }

        $existing = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $course, $orderItemId, $plan): Enrollment {
            $startedAt = now();
            $expiresAt = $plan?->calculateExpiresAt($startedAt);

            $enrollment = Enrollment::query()->create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'course_plan_id' => $plan?->id,
                'order_item_id' => $orderItemId,
                'status' => EnrollmentStatus::Active,
                'progress_percent' => 0,
                'enrolled_at' => $startedAt,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'grant_certificate' => $plan?->grant_certificate ?? false,
            ]);

            if ($plan !== null) {
                $this->entitlementSync->snapshotEntitlementsForEnrollment($enrollment, $plan);
            }

            return $enrollment->fresh(['coursePlan', 'entitlements']);
        });
    }

    public function enrollWithPlan(User $user, CoursePlan $plan): Enrollment
    {
        $plan->loadMissing('course');

        if ($plan->isFree()) {
            return $this->enroll($user, $plan->course, null, $plan);
        }

        throw new DomainException('Paid plans require checkout and payment.', 402);
    }

    /**
     * Direct enrollment for free courses (paid courses must go through checkout).
     *
     * @return array{enrollment: Enrollment, created: bool}
     */
    public function enrollDirect(User $user, Course $course): array
    {
        $existing = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->first();

        if ($existing) {
            return ['enrollment' => $existing, 'created' => false];
        }

        return match ($course->access_type) {
            AccessType::Free => [
                'enrollment' => $this->enroll($user, $course),
                'created' => true,
            ],
            AccessType::Paid => throw new DomainException('Paid course requires checkout and payment.', 402),
            AccessType::School => throw new DomainException('School courses require school membership.', 403),
            AccessType::Closed => throw new DomainException('This course is closed for enrollment.', 403),
        };
    }
}
