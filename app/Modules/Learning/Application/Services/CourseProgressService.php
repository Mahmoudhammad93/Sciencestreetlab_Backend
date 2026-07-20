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
    public function markTopicComplete(Enrollment $enrollment, Topic $topic, float $watchPercent): void
    {
        DB::transaction(function () use ($enrollment, $topic, $watchPercent): void {
            $enrollment->topicCompletions()->updateOrCreate(
                ['topic_id' => $topic->id],
                [
                    'watch_progress_percent' => $watchPercent,
                    'completed_at' => $watchPercent >= 90 ? now() : null,
                ]
            );

            if ($watchPercent >= 90) {
                $this->recalculateLessonProgress($enrollment, $topic->lesson);
            }
        });
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
