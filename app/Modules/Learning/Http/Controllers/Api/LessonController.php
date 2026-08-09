<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CoursePresenter;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LessonController extends Controller
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CoursePresenter $presenter,
        private readonly QuizAttemptService $quizAttempts,
    ) {}

    public function index(Request $request, string $slug): JsonResponse
    {
        $course = Course::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $user = $request->user('sanctum');
        $enrollment = $user
            ? $this->access->enrollmentFor($user, $course)
            : null;

        if ($enrollment) {
            $enrollment->load(['topicCompletions', 'lessonCompletions', 'course.lessons.topics']);
        }

        return response()->json([
            'data' => $this->presenter->lessonOutline($course, $enrollment),
        ]);
    }

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        if (! $lesson->is_published) {
            return response()->json(['message' => 'Lesson not found'], 404);
        }

        $course = $lesson->course;

        if (! $course || ! $course->is_published) {
            return response()->json(['message' => 'Lesson not found'], 404);
        }

        try {
            $enrollment = $this->access->requireEnrollment($request->user(), $course);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NOT_ENROLLED'], 403);
        }

        if (! $this->access->canAccessLesson($enrollment, $lesson)) {
            return response()->json(['message' => 'Lesson is locked.', 'code' => 'LESSON_LOCKED'], 403);
        }

        $this->touchLastAccessed($enrollment, $lesson);

        $locale = app()->getLocale();
        $lesson->load([
            'topics' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order'),
            'quizzes',
        ]);
        $enrollment->load('topicCompletions');

        $previous = $this->adjacentLesson($course, $lesson, -1);
        $next = $this->adjacentLesson($course, $lesson, 1);

        return response()->json([
            'data' => [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->getTranslation('title', $locale),
                'content' => $lesson->getTranslation('content', $locale) ?: null,
                'lesson_type' => $lesson->lesson_type->value,
                'is_completed' => $this->access->isLessonComplete($enrollment, $lesson),
                'topics' => $lesson->topics->map(function ($topic) use ($locale, $enrollment) {
                    $completion = $enrollment->topicCompletions->firstWhere('topic_id', $topic->id);

                    return [
                        'id' => $topic->id,
                        'slug' => $topic->slug,
                        'title' => $topic->getTranslation('title', $locale),
                        'content' => $topic->getTranslation('content', $locale) ?: null,
                        'content_type' => $topic->content_type,
                        'has_video' => filled($topic->video_url),
                        'is_locked' => ! $this->access->canAccessTopic($enrollment, $topic),
                        'is_completed' => $completion && (float) $completion->watch_progress_percent >= 90,
                        'watch_progress_percent' => $completion ? (float) $completion->watch_progress_percent : 0,
                        'watched_seconds' => $completion?->watched_seconds,
                        'duration_seconds' => $completion?->duration_seconds,
                        'last_position_seconds' => $completion?->last_position_seconds,
                    ];
                })->values(),
                'quizzes' => $lesson->quizzes->map(fn ($quiz) => [
                    'id' => $quiz->id,
                    'title' => $quiz->getTranslation('title', $locale),
                    'is_required' => $quiz->is_required,
                    'is_locked' => ! $this->access->canAccessQuiz($enrollment, $quiz),
                    'is_passed' => $this->quizAttempts->hasPassed($request->user(), $quiz),
                ])->values(),
                'previous_lesson_id' => $previous?->id,
                'next_lesson_id' => $next?->id,
            ],
        ]);
    }

    private function touchLastAccessed(Enrollment $enrollment, Lesson $lesson): void
    {
        $enrollment->update([
            'last_accessed_lesson_id' => $lesson->id,
            'last_accessed_at' => now(),
        ]);
    }

    private function adjacentLesson(Course $course, Lesson $lesson, int $direction): ?Lesson
    {
        $lessons = $course->lessons()->where('is_published', true)->orderBy('sort_order')->get();
        $index = $lessons->search(fn (Lesson $l) => $l->id === $lesson->id);

        if ($index === false) {
            return null;
        }

        return $lessons->get($index + $direction);
    }
}
