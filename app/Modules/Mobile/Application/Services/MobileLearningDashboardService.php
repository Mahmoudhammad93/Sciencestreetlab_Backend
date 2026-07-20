<?php

declare(strict_types=1);

namespace App\Modules\Mobile\Application\Services;

use App\Models\User;
use App\Modules\Learning\Application\Services\CurriculumService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;

final class MobileLearningDashboardService
{
    public function __construct(
        private readonly CurriculumService $curriculum,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $enrollments = Enrollment::query()
            ->where('user_id', $user->id)
            ->with('course')
            ->latest('enrolled_at')
            ->get();

        $locale = $user->locale ?? 'ar';

        return [
            'enrollments' => $enrollments->map(function (Enrollment $enrollment) use ($locale) {
                $curriculum = $this->curriculum->build($enrollment);
                $nextLesson = collect($curriculum['lessons'])->first(fn ($l) => ! $l['is_completed'] && ! $l['is_locked']);

                return [
                    'id' => $enrollment->id,
                    'course_slug' => $enrollment->course->slug,
                    'course_title' => $enrollment->course->getTranslation('title', $locale),
                    'progress_percent' => (float) $enrollment->progress_percent,
                    'status' => $enrollment->status->value,
                    'next_lesson' => $nextLesson ? [
                        'slug' => $nextLesson['slug'],
                        'title' => $nextLesson['title'],
                    ] : null,
                ];
            }),
        ];
    }
}
