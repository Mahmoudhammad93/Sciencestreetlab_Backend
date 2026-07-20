<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Models\User;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;

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
}
