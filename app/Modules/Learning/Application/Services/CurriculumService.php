<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;

final class CurriculumService
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly QuizAttemptService $quizAttempts,
    ) {}

    public function build(Enrollment $enrollment): array
    {
        $enrollment->load([
            'course.lessons.topics',
            'course.lessons.quizzes',
            'topicCompletions',
            'lessonCompletions',
        ]);

        $lessons = [];

        foreach ($enrollment->course->lessons as $lesson) {
            if (! $lesson->is_published) {
                continue;
            }

            $topics = [];
            foreach ($lesson->topics as $topic) {
                if (! $topic->is_published) {
                    continue;
                }

                $completion = $enrollment->topicCompletions->firstWhere('topic_id', $topic->id);
                $topics[] = [
                    'id' => $topic->id,
                    'slug' => $topic->slug,
                    'title' => $topic->getTranslation('title', app()->getLocale()),
                    'content_type' => $topic->content_type,
                    'is_locked' => ! $this->access->canAccessTopic($enrollment, $topic),
                    'is_completed' => $completion && (float) $completion->watch_progress_percent >= 90,
                    'watch_progress_percent' => $completion ? (float) $completion->watch_progress_percent : 0,
                ];
            }

            $quizzes = [];
            foreach ($lesson->quizzes as $quiz) {
                $quizzes[] = [
                    'id' => $quiz->id,
                    'title' => $quiz->getTranslation('title', app()->getLocale()),
                    'passing_score' => (float) $quiz->passing_score,
                    'is_locked' => ! $this->access->canAccessQuiz($enrollment, $quiz),
                    'is_passed' => $this->quizAttempts->hasPassed($enrollment->user, $quiz),
                    'is_required' => $quiz->is_required,
                ];
            }

            $lessons[] = [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->getTranslation('title', app()->getLocale()),
                'lesson_type' => $lesson->lesson_type->value,
                'is_locked' => ! $this->access->canAccessLesson($enrollment, $lesson),
                'is_completed' => $this->access->isLessonComplete($enrollment, $lesson),
                'topics' => $topics,
                'quizzes' => $quizzes,
            ];
        }

        return [
            'enrollment_id' => $enrollment->id,
            'course_id' => $enrollment->course_id,
            'progress_percent' => (float) $enrollment->progress_percent,
            'status' => $enrollment->status->value,
            'lessons' => $lessons,
        ];
    }
}
