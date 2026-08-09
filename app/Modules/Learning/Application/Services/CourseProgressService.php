<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Domain\Events\CourseCompleted;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Support\Facades\DB;

final class CourseProgressService
{
    /**
     * @param  array{
     *     watch_progress_percent?: float|int|null,
     *     watched_seconds?: int|null,
     *     duration?: int|null,
     *     duration_seconds?: int|null,
     *     completed?: bool|null
     * }  $progress
     */
    public function recordTopicProgress(Enrollment $enrollment, Topic $topic, array $progress): array
    {
        $watchedSeconds = isset($progress['watched_seconds']) ? (int) $progress['watched_seconds'] : null;
        $durationSeconds = isset($progress['duration_seconds'])
            ? (int) $progress['duration_seconds']
            : (isset($progress['duration']) ? (int) $progress['duration'] : null);

        $watchPercent = isset($progress['watch_progress_percent'])
            ? (float) $progress['watch_progress_percent']
            : null;

        if ($watchPercent === null && $watchedSeconds !== null && $durationSeconds !== null && $durationSeconds > 0) {
            $watchPercent = round(($watchedSeconds / $durationSeconds) * 100, 2);
        }

        $watchPercent ??= 0.0;
        $watchPercent = min(100.0, max(0.0, $watchPercent));

        if (! empty($progress['completed'])) {
            $watchPercent = max($watchPercent, 100.0);
        }

        DB::transaction(function () use ($enrollment, $topic, $watchPercent, $watchedSeconds, $durationSeconds): void {
            $existing = $enrollment->topicCompletions()->where('topic_id', $topic->id)->first();

            $payload = [
                'watch_progress_percent' => max(
                    $watchPercent,
                    $existing ? (float) $existing->watch_progress_percent : 0
                ),
                'completed_at' => ($watchPercent >= 90 || ($existing && (float) $existing->watch_progress_percent >= 90))
                    ? ($existing?->completed_at ?? now())
                    : null,
            ];

            if ($watchedSeconds !== null) {
                $payload['watched_seconds'] = max(
                    $watchedSeconds,
                    $existing?->watched_seconds ?? 0
                );
                $payload['last_position_seconds'] = $watchedSeconds;
            }

            if ($durationSeconds !== null) {
                $payload['duration_seconds'] = $durationSeconds;
            }

            if ($payload['watch_progress_percent'] >= 90 && $payload['completed_at'] === null) {
                $payload['completed_at'] = now();
            }

            $enrollment->topicCompletions()->updateOrCreate(
                ['topic_id' => $topic->id],
                $payload
            );

            $enrollment->update([
                'last_accessed_lesson_id' => $topic->lesson_id,
                'last_accessed_topic_id' => $topic->id,
                'last_accessed_at' => now(),
            ]);

            if ($payload['watch_progress_percent'] >= 90) {
                $this->recalculateLessonProgress($enrollment, $topic->lesson);
            }
        });

        $completion = $enrollment->topicCompletions()->where('topic_id', $topic->id)->first();

        return [
            'topic_id' => $topic->id,
            'watch_progress_percent' => (float) ($completion?->watch_progress_percent ?? $watchPercent),
            'watched_seconds' => $completion?->watched_seconds,
            'duration_seconds' => $completion?->duration_seconds,
            'last_position_seconds' => $completion?->last_position_seconds,
            'completed' => $completion && (float) $completion->watch_progress_percent >= 90,
            'course_progress_percent' => (float) $enrollment->fresh()->progress_percent,
        ];
    }

    public function markTopicComplete(Enrollment $enrollment, Topic $topic, float $watchPercent): void
    {
        $this->recordTopicProgress($enrollment, $topic, [
            'watch_progress_percent' => $watchPercent,
            'completed' => $watchPercent >= 90,
        ]);
    }

    public function recalculateCourseProgress(Enrollment $enrollment): void
    {
        $lessons = $enrollment->course->lessons()->where('is_published', true)->get();
        $total = $lessons->count();

        if ($total === 0) {
            return;
        }

        $completed = $lessons->filter(fn (Lesson $lesson) => $this->isLessonComplete($enrollment, $lesson));
        $percent = round(($completed->count() / $total) * 100, 2);

        $enrollment->update(['progress_percent' => $percent]);

        if ($percent >= 100 && $enrollment->status !== EnrollmentStatus::Completed) {
            $enrollment->update([
                'status' => EnrollmentStatus::Completed,
                'completed_at' => now(),
            ]);
            event(new CourseCompleted($enrollment));
        }
    }

    public function recalculateLessonProgress(Enrollment $enrollment, Lesson $lesson): void
    {
        if ($this->isLessonComplete($enrollment, $lesson)) {
            $enrollment->lessonCompletions()->updateOrCreate(
                ['lesson_id' => $lesson->id],
                ['completed_at' => now()]
            );
        }

        $this->recalculateCourseProgress($enrollment);
    }

    /**
     * @return array<string, mixed>
     */
    public function courseProgressPayload(Enrollment $enrollment): array
    {
        $enrollment->loadMissing(['course.lessons', 'lastAccessedLesson', 'lastAccessedTopic']);

        $lessons = $enrollment->course->lessons()->where('is_published', true)->get();
        $completedLessons = $lessons->filter(
            fn (Lesson $lesson) => $this->isLessonComplete($enrollment, $lesson)
        )->count();

        $continueLesson = $enrollment->lastAccessedLesson
            ?? $lessons->first(fn (Lesson $lesson) => ! $this->isLessonComplete($enrollment, $lesson));

        return [
            'course_id' => $enrollment->course_id,
            'progress' => (float) $enrollment->progress_percent,
            'completed_lessons' => $completedLessons,
            'total_lessons' => $lessons->count(),
            'last_lesson_id' => $enrollment->last_accessed_lesson_id,
            'last_topic_id' => $enrollment->last_accessed_topic_id,
            'last_accessed_at' => $enrollment->last_accessed_at?->toIso8601String(),
            'continue_from' => $continueLesson ? [
                'lesson_id' => $continueLesson->id,
                'lesson_slug' => $continueLesson->slug,
                'lesson_title' => $continueLesson->getTranslation('title', app()->getLocale()),
                'topic_id' => $enrollment->last_accessed_topic_id,
            ] : null,
        ];
    }

    private function isLessonComplete(Enrollment $enrollment, Lesson $lesson): bool
    {
        $topics = $lesson->topics()->where('is_published', true)->get();
        $completedTopics = $enrollment->topicCompletions()
            ->whereIn('topic_id', $topics->pluck('id'))
            ->where('watch_progress_percent', '>=', 90)
            ->count();

        if ($topics->count() === 0 || $completedTopics < $topics->count()) {
            return false;
        }

        $requiredQuizzes = Quiz::query()
            ->where('quizable_type', Lesson::class)
            ->where('quizable_id', $lesson->id)
            ->where('is_required', true)
            ->pluck('id');

        if ($requiredQuizzes->isEmpty()) {
            return true;
        }

        $passedCount = QuizAttempt::query()
            ->where('user_id', $enrollment->user_id)
            ->whereIn('quiz_id', $requiredQuizzes)
            ->where('passed', true)
            ->distinct('quiz_id')
            ->count('quiz_id');

        return $passedCount >= $requiredQuizzes->count();
    }
}
