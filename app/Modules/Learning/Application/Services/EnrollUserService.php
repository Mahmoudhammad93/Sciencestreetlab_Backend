<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Models\User;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use DomainException;

final class EnrollUserService
{
    public function enroll(User $user, Course $course, ?int $orderItemId = null): Enrollment
    {
        return Enrollment::query()->firstOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'order_item_id' => $orderItemId,
                'status' => EnrollmentStatus::Active,
                'progress_percent' => 0,
                'enrolled_at' => now(),
                'started_at' => now(),
            ]
        );
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
