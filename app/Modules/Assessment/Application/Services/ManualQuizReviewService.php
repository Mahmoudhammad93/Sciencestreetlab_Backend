<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Learning\Application\Services\CourseProgressService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Teacher/admin manual scoring for long-answer (and other needs_manual_review) items.
 */
final class ManualQuizReviewService
{
    public function __construct(
        private readonly CourseProgressService $progressService,
        private readonly OfficialQuizScoreService $officialScores,
    ) {}

    public function gradeAnswer(
        QuizAttemptAnswer $answer,
        float $pointsAwarded,
        ?bool $isCorrect = null,
    ): QuizAttempt {
        $answer->loadMissing(['attempt.quiz', 'question']);

        $attempt = $answer->attempt;
        if ($attempt === null) {
            throw new DomainException('Answer has no attempt.');
        }

        if ($attempt->status !== AttemptStatus::PendingReview) {
            throw new DomainException('Attempt is not awaiting manual review.');
        }

        $maxPoints = (float) ($answer->question?->points ?? 0);
        if ($pointsAwarded < 0 || ($maxPoints > 0 && $pointsAwarded > $maxPoints)) {
            throw new DomainException('Points must be between 0 and the question max.');
        }

        if ($isCorrect === null) {
            $isCorrect = $maxPoints > 0 ? $pointsAwarded >= $maxPoints : $pointsAwarded > 0;
        }

        return DB::transaction(function () use ($answer, $pointsAwarded, $isCorrect, $attempt): QuizAttempt {
            $answer->update([
                'points_awarded' => $pointsAwarded,
                'is_correct' => $isCorrect,
                'needs_manual_review' => false,
            ]);

            return $this->finalizeIfReady($attempt->fresh(['answers', 'quiz', 'enrollment']));
        });
    }

    public function finalizeIfReady(QuizAttempt $attempt): QuizAttempt
    {
        $attempt->loadMissing(['answers', 'quiz.quizable', 'enrollment']);

        $stillPending = $attempt->answers->contains(
            fn (QuizAttemptAnswer $a) => (bool) $a->needs_manual_review
        );

        if ($stillPending) {
            return $attempt;
        }

        $score = (float) $attempt->answers->sum(fn (QuizAttemptAnswer $a) => (float) ($a->points_awarded ?? 0));
        $maxScore = (float) ($attempt->max_score ?? 0);
        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0.0;
        $passed = $percentage >= (float) $attempt->quiz->passing_score;

        $attempt->update([
            'status' => AttemptStatus::Graded,
            'score' => $score,
            'percentage' => $percentage,
            'passed' => $passed,
            'graded_at' => now(),
        ]);

        $attempt = $attempt->fresh(['quiz.quizable', 'enrollment']);
        $this->officialScores->markOfficialOnSubmit($attempt);

        if ($attempt->is_official && $passed) {
            event(new QuizPassed($attempt));

            $quiz = $attempt->quiz->load('quizable');
            $enrollment = $attempt->enrollment?->fresh();

            if ($enrollment !== null) {
                if ($quiz->quizable instanceof Lesson) {
                    $this->progressService->recalculateLessonProgress($enrollment, $quiz->quizable);
                } else {
                    $this->progressService->recalculateCourseProgress($enrollment);
                }
            }
        }

        return $attempt->fresh(['answers', 'quiz', 'user']);
    }
}
