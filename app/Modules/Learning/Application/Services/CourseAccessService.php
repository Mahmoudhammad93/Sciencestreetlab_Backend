<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Application\Services\OfficialQuizScoreService;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use DomainException;

final class CourseAccessService
{
    public function __construct(
        private readonly CoursePlanAccessService $planAccess,
        private readonly OfficialQuizScoreService $officialScores,
    ) {}

    public function enrollmentFor(User $user, Course $course): ?Enrollment
    {
        $enrollment = Enrollment::query()
            ->with(['coursePlan', 'entitlements'])
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->first();

        if ($enrollment === null) {
            return null;
        }

        if (! $this->planAccess->isEnrollmentActive($enrollment)) {
            return null;
        }

        return $enrollment;
    }

    public function requireEnrollment(User $user, Course $course): Enrollment
    {
        $enrollment = $this->enrollmentFor($user, $course);

        if (! $enrollment) {
            throw new DomainException('Not enrolled in this course.');
        }

        return $enrollment;
    }

    public function canAccessLesson(Enrollment $enrollment, Lesson $lesson): bool
    {
        if ($lesson->course_id !== $enrollment->course_id) {
            return false;
        }

        if ($this->planAccess->usesPlanEntitlements($enrollment)) {
            if (! $this->planAccess->canAccessLesson($enrollment, $lesson)) {
                return false;
            }
        }

        $enrollment->loadMissing('course');

        if ($enrollment->course?->access_type === AccessType::Free) {
            return true;
        }

        $lessons = $enrollment->course->lessons()->where('is_published', true)->orderBy('sort_order')->get();
        $index = $lessons->search(fn (Lesson $l) => $l->id === $lesson->id);

        if ($index === false) {
            return false;
        }

        if ($index === 0) {
            return true;
        }

        return $this->isLessonComplete($enrollment, $lessons[$index - 1]);
    }

    public function canAccessTopic(Enrollment $enrollment, Topic $topic): bool
    {
        $lesson = $topic->lesson;

        if (! $this->canAccessLesson($enrollment, $lesson)) {
            return false;
        }

        if ($this->planAccess->usesPlanEntitlements($enrollment)
            && ! $this->planAccess->canAccessTopic($enrollment, $topic)) {
            return false;
        }

        $enrollment->loadMissing('course');
        if ($enrollment->course?->access_type === AccessType::Free) {
            return true;
        }

        $topics = $lesson->topics()->where('is_published', true)->orderBy('sort_order')->get();
        $index = $topics->search(fn (Topic $t) => $t->id === $topic->id);

        if ($index === false || $index === 0) {
            return $index !== false;
        }

        return $this->isTopicComplete($enrollment, $topics[$index - 1]);
    }

    public function canAccessQuiz(Enrollment $enrollment, Quiz $quiz): bool
    {
        if ($this->planAccess->usesPlanEntitlements($enrollment)
            && ! $this->planAccess->canAccessQuiz($enrollment, $quiz)) {
            return false;
        }

        $lesson = $quiz->quizable;

        if ($lesson instanceof Lesson) {
            return $this->canAccessLesson($enrollment, $lesson);
        }

        return false;
    }

    public function canAccessInteractiveActivity(Enrollment $enrollment, InteractiveActivity $activity): bool
    {
        if ($this->planAccess->usesPlanEntitlements($enrollment)
            && ! $this->planAccess->canAccessInteractiveActivity($enrollment, $activity)) {
            return false;
        }

        if ($activity->lesson_id === null) {
            return false;
        }

        $lesson = $activity->lesson;

        return $lesson instanceof Lesson && $this->canAccessLesson($enrollment, $lesson);
    }

    public function isLessonComplete(Enrollment $enrollment, Lesson $lesson): bool
    {
        $topics = $lesson->topics()->where('is_published', true)->get();
        $completedTopics = $enrollment->topicCompletions()
            ->whereIn('topic_id', $topics->pluck('id'))
            ->where('watch_progress_percent', '>=', 90)
            ->count();

        if ($topics->count() === 0 || $completedTopics < $topics->count()) {
            return false;
        }

        $quizzes = Quiz::query()
            ->where('quizable_type', Lesson::class)
            ->where('quizable_id', $lesson->id)
            ->where('is_required', true)
            ->get();

        foreach ($quizzes as $quiz) {
            if ($this->planAccess->usesPlanEntitlements($enrollment)) {
                if (! $this->planAccess->canAccessQuiz($enrollment, $quiz)) {
                    continue;
                }
            }

            if (! $this->quizPassedForEnrollment($enrollment, $quiz)) {
                return false;
            }
        }

        return true;
    }

    public function isTopicComplete(Enrollment $enrollment, Topic $topic): bool
    {
        return $enrollment->topicCompletions()
            ->where('topic_id', $topic->id)
            ->where('watch_progress_percent', '>=', 90)
            ->exists();
    }

    private function quizPassedForEnrollment(Enrollment $enrollment, Quiz $quiz): bool
    {
        if ($this->officialScores->hasPassedOfficially($enrollment->user, $quiz, $enrollment)) {
            return true;
        }

        if ($this->officialScores->officialAttempt($enrollment->user, $quiz, $enrollment) !== null) {
            return false;
        }

        return QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $enrollment->user_id)
            ->where('passed', true)
            ->exists();
    }
}
