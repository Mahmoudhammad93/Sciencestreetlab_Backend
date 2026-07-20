<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Assessment\Infrastructure\Grading\QuestionGraderRegistry;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Learning\Application\Services\CourseProgressService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use DomainException;
use Illuminate\Support\Facades\DB;

final class QuizAttemptService
{
    public function __construct(
        private readonly QuestionGraderRegistry $graderRegistry,
        private readonly CourseProgressService $progressService,
    ) {}

    public function start(User $user, Quiz $quiz, Enrollment $enrollment): QuizAttempt
    {
        if ($enrollment->user_id !== $user->id) {
            throw new DomainException('Enrollment does not belong to user.');
        }

        $attemptCount = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->count();

        if ($quiz->max_attempts && $attemptCount >= $quiz->max_attempts) {
            throw new DomainException('Maximum quiz attempts reached.');
        }

        $inProgress = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->where('status', AttemptStatus::InProgress)
            ->exists();

        if ($inProgress) {
            throw new DomainException('An attempt is already in progress.');
        }

        return QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'enrollment_id' => $enrollment->id,
            'attempt_number' => $attemptCount + 1,
            'status' => AttemptStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{question_id: int, selected_option_ids?: array<int>, text_answer?: string}>  $answers
     */
    public function submit(QuizAttempt $attempt, array $answers): QuizAttempt
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new DomainException('Attempt is not in progress.');
        }

        return DB::transaction(function () use ($attempt, $answers): QuizAttempt {
            $quiz = $attempt->quiz()->with('questions.options')->firstOrFail();
            $score = 0.0;
            $maxScore = 0.0;

            foreach ($quiz->questions as $question) {
                $maxScore += (float) $question->points;
                $payload = collect($answers)->firstWhere('question_id', $question->id);

                $answer = QuizAttemptAnswer::query()->updateOrCreate(
                    ['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id],
                    [
                        'selected_option_ids' => $payload['selected_option_ids'] ?? null,
                        'text_answer' => $payload['text_answer'] ?? null,
                    ]
                );

                $isCorrect = $this->graderRegistry->grade($question, $answer);
                $points = $isCorrect ? (float) $question->points : 0;
                $answer->update(['is_correct' => $isCorrect, 'points_awarded' => $points]);
                $score += $points;
            }

            $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0;
            $passed = $percentage >= (float) $quiz->passing_score;

            $attempt->update([
                'status' => AttemptStatus::Graded,
                'score' => $score,
                'max_score' => $maxScore,
                'percentage' => $percentage,
                'passed' => $passed,
                'submitted_at' => now(),
                'graded_at' => now(),
                'time_spent_seconds' => now()->diffInSeconds($attempt->started_at),
            ]);

            if ($passed) {
                event(new QuizPassed($attempt));

                $quiz->load('quizable');
                $enrollment = $attempt->enrollment->fresh();

                if ($quiz->quizable instanceof \App\Modules\Learning\Infrastructure\Persistence\Models\Lesson) {
                    $this->progressService->recalculateLessonProgress($enrollment, $quiz->quizable);
                } else {
                    $this->progressService->recalculateCourseProgress($enrollment);
                }
            }

            return $attempt->fresh(['answers', 'quiz']);
        });
    }

    public function hasPassed(User $user, Quiz $quiz): bool
    {
        return QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->where('passed', true)
            ->exists();
    }
}
