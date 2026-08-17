<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;

final class CoursePresenter
{
    public function __construct(
        private readonly CourseAccessService $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Course $course, ?User $user = null, bool $includeLessonOutline = false): array
    {
        $locale = app()->getLocale();
        $enrollment = $user ? $this->access->enrollmentFor($user, $course) : null;
        $product = $course->relationLoaded('product')
            ? $course->product
            : $this->resolveProduct($course);

        if ($enrollment) {
            $enrollment->loadMissing(['topicCompletions', 'lessonCompletions']);
        }

        $payload = [
            'id' => $course->id,
            'uuid' => $course->uuid,
            'slug' => $course->slug,
            'title' => $course->getTranslation('title', $locale),
            'image' => $course->image_url,
            'short_description' => $course->getTranslation('short_description', $locale) ?: null,
            'long_description' => $course->getTranslation('description', $locale) ?: null,
            'access_type' => $course->access_type->value,
            'price' => $this->resolvePrice($course, $product),
            'currency' => $product?->currency,
            'lessons_count' => $course->lessons_count ?? $course->lessons()->where('is_published', true)->count(),
            'estimated_time' => $course->estimated_hours !== null
                ? (float) $course->estimated_hours
                : null,
            'enrollment_status' => $enrollment ? $enrollment->status->value : 'not_enrolled',
            'progress' => $enrollment ? (float) $enrollment->progress_percent : null,
            'is_enrolled' => $enrollment !== null,
        ];

        if ($includeLessonOutline) {
            $payload['lessons'] = $this->lessonOutline($course, $enrollment);
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lessonOutline(Course $course, ?Enrollment $enrollment): array
    {
        $locale = app()->getLocale();

        $lessons = $course->lessons()
            ->where('is_published', true)
            ->withCount([
                'topics' => fn ($q) => $q->where('is_published', true),
                'quizzes',
            ])
            ->with([
                'topics' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order'),
                'quizzes',
            ])
            ->orderBy('sort_order')
            ->get();

        if ($enrollment) {
            $enrollment->loadMissing(['topicCompletions', 'lessonCompletions']);
        }

        return $lessons->map(function (Lesson $lesson) use ($locale, $enrollment) {
            $canAccessLesson = $enrollment ? $this->access->canAccessLesson($enrollment, $lesson) : false;
            $quiz = $lesson->quizzes->first();

            return [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->getTranslation('title', $locale),
                'sort_order' => (int) $lesson->sort_order,
                'lesson_type' => $lesson->lesson_type->value,
                'status' => $this->lessonStatus($lesson, $enrollment),
                'is_locked' => ! $canAccessLesson,
                'topics_count' => $lesson->topics_count,
                'has_quiz' => $lesson->quizzes_count > 0,
                'quiz' => $quiz ? [
                    'id' => $quiz->id,
                    'title' => $quiz->getTranslation('title', $locale),
                    'is_required' => (bool) $quiz->is_required,
                ] : null,
                'topics' => $lesson->topics->map(function (Topic $topic) use ($locale, $canAccessLesson) {
                    return [
                        'id' => $topic->id,
                        'lesson_id' => $topic->lesson_id,
                        'slug' => $topic->slug,
                        'sort_order' => (int) $topic->sort_order,
                        'content_type' => $topic->content_type,
                        'title' => $topic->getTranslation('title', $locale),
                        // Keep video URLs only for unlocked lessons
                        'video_url' => $canAccessLesson ? $topic->video_url : null,
                        'video_provider' => $canAccessLesson ? $topic->video_provider : null,
                        'is_published' => (bool) $topic->is_published,
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    private function lessonStatus(Lesson $lesson, ?Enrollment $enrollment): string
    {
        if (! $enrollment) {
            return 'not_started';
        }

        if ($this->access->isLessonComplete($enrollment, $lesson)) {
            return 'completed';
        }

        if (! $this->access->canAccessLesson($enrollment, $lesson)) {
            return 'locked';
        }

        $topicIds = $lesson->topics->pluck('id');
        $hasProgress = $enrollment->topicCompletions
            ->whereIn('topic_id', $topicIds)
            ->isNotEmpty();

        if ($hasProgress || $enrollment->last_accessed_lesson_id === $lesson->id) {
            return 'in_progress';
        }

        return 'not_started';
    }

    private function resolveProduct(Course $course): ?Product
    {
        if (! $course->product_id) {
            return null;
        }

        return Product::query()->find($course->product_id);
    }

    /**
     * @return array{amount: float|null, is_free: bool}|null
     */
    private function resolvePrice(Course $course, ?Product $product): ?array
    {
        if ($course->access_type === AccessType::Free) {
            return ['amount' => 0.0, 'is_free' => true];
        }

        if ($product) {
            return [
                'amount' => (float) $product->price,
                'is_free' => false,
            ];
        }

        return null;
    }
}
