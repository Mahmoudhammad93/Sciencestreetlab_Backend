<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityAttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Domain\Events\QuizPassed;
use App\Modules\Assessment\Infrastructure\Grading\QuestionGraderRegistry;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivityAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptQuestion;
use App\Modules\Learning\Application\Services\CourseProgressService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class QuizAttemptService
{
    public function __construct(
        private readonly QuestionGraderRegistry $graderRegistry,
        private readonly CourseProgressService $progressService,
        private readonly QuestionSelectionService $selectionService,
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
            throw new DomainException('MAX_ATTEMPTS_REACHED: Maximum quiz attempts reached.', 422);
        }

        $inProgress = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->where('status', AttemptStatus::InProgress)
            ->latest('id')
            ->first();

        // Resume instead of blocking — refresh / re-click should continue the same attempt
        if ($inProgress) {
            return $inProgress->load(['frozenQuestions.question.options', 'quiz', 'answers']);
        }

        return DB::transaction(function () use ($user, $quiz, $enrollment, $attemptCount) {
            $attempt = QuizAttempt::query()->create([
                'quiz_id' => $quiz->id,
                'user_id' => $user->id,
                'enrollment_id' => $enrollment->id,
                'attempt_number' => $attemptCount + 1,
                'status' => AttemptStatus::InProgress,
                'started_at' => now(),
            ]);

            $questions = $this->resolveQuestionsForNewAttempt($quiz);
            $this->freezeQuestions($attempt, $questions);

            return $attempt->fresh(['frozenQuestions.question.options', 'quiz']);
        });
    }

    /**
     * @return Collection<int, Question>
     */
    public function questionsForAttempt(QuizAttempt $attempt): Collection
    {
        $attempt->loadMissing(['frozenQuestions.question.options', 'quiz.questions.options']);

        if ($attempt->frozenQuestions->isNotEmpty()) {
            return $attempt->frozenQuestions
                ->map(fn (QuizAttemptQuestion $row) => $row->question)
                ->filter()
                ->values();
        }

        // Legacy attempts before freeze table
        return $attempt->quiz->questions()->with('options')->orderBy('sort_order')->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     */
    public function submit(QuizAttempt $attempt, array $answers): QuizAttempt
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new DomainException('Attempt is not in progress.');
        }

        return DB::transaction(function () use ($attempt, $answers): QuizAttempt {
            $attempt->loadMissing(['quiz.interactiveActivities']);
            $questions = $this->questionsForAttempt($attempt);
            $score = 0.0;
            $maxScore = 0.0;
            $needsReview = false;

            foreach ($questions as $question) {
                $maxScore += (float) $question->points;
                $payload = collect($answers)->firstWhere('question_id', $question->id);
                if (is_array($payload)) {
                    $payload = array_merge(
                        ['question_id' => $question->id],
                        $this->normalizeFrontendAnswer($payload)
                    );
                }

                $answer = QuizAttemptAnswer::query()->updateOrCreate(
                    ['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id],
                    $this->mapAnswerPayload($payload ?? [])
                );

                $isCorrect = $this->graderRegistry->grade($question, $answer->fresh());
                $answer->refresh();

                if ($answer->needs_manual_review || $question->question_type === QuestionType::LongAnswer) {
                    $needsReview = true;
                    $points = 0.0;
                    $isCorrect = null;
                } else {
                    $points = $isCorrect ? (float) $question->points : 0.0;
                }

                $answer->update([
                    'is_correct' => $isCorrect,
                    'points_awarded' => $points,
                ]);

                $score += $points;
            }

            foreach ($attempt->quiz->interactiveActivities as $activity) {
                $pivotPoints = (float) ($activity->pivot->points ?? $activity->points ?? 0);
                $maxScore += $pivotPoints;

                $activityAttempt = InteractiveActivityAttempt::query()
                    ->where('quiz_attempt_id', $attempt->id)
                    ->where('activity_id', $activity->id)
                    ->where('status', InteractiveActivityAttemptStatus::Completed)
                    ->latest('id')
                    ->first();

                if (! $activityAttempt) {
                    continue;
                }

                $earned = $activityAttempt->score_verified
                    ? ($activityAttempt->verified_score ?? 0.0)
                    : ($activityAttempt->client_score ?? 0.0);
                $activityMax = (float) ($activityAttempt->max_score ?: $activity->points ?: 100);

                if ($activityMax > 0 && $pivotPoints > 0) {
                    $score += min($pivotPoints, ($earned / $activityMax) * $pivotPoints);
                }
            }

            $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0;
            $passed = ! $needsReview && $percentage >= (float) $attempt->quiz->passing_score;

            // Calculate time spent in seconds, ensuring it's always non-negative and integer
            $timeSpentSeconds = max(
                0,
                (int) $attempt->started_at->diffInSeconds(now(), true)
            );

            $attempt->update([
                'status' => $needsReview ? AttemptStatus::PendingReview : AttemptStatus::Graded,
                'score' => $score,
                'max_score' => $maxScore,
                'percentage' => $percentage,
                'passed' => $needsReview ? null : $passed,
                'submitted_at' => now(),
                'graded_at' => $needsReview ? null : now(),
                'time_spent_seconds' => $timeSpentSeconds,
            ]);

            if ($passed) {
                event(new QuizPassed($attempt));

                $quiz = $attempt->quiz->load('quizable');
                $enrollment = $attempt->enrollment->fresh();

                if ($quiz->quizable instanceof Lesson) {
                    $this->progressService->recalculateLessonProgress($enrollment, $quiz->quizable);
                } else {
                    $this->progressService->recalculateCourseProgress($enrollment);
                }
            }

            return $attempt->fresh(['answers', 'quiz', 'frozenQuestions']);
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

    /**
     * Save a single answer during an in-progress attempt (does not finalize grading).
     *
     * @param  array<string, mixed>  $frontendPayload  either nested {answer:{...}} or legacy flat fields
     */
    public function saveAnswer(QuizAttempt $attempt, int $questionId, array $frontendPayload): QuizAttemptAnswer
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new DomainException('ATTEMPT_EXPIRED: Attempt is not in progress.', 422);
        }

        $questionIds = $this->questionsForAttempt($attempt)->pluck('id');
        if (! $questionIds->contains($questionId)) {
            throw new DomainException('QUESTION_NOT_FOUND: Question is not part of this attempt.', 404);
        }

        $mapped = $this->mapAnswerPayload($this->normalizeFrontendAnswer($frontendPayload));

        return QuizAttemptAnswer::query()->updateOrCreate(
            ['quiz_attempt_id' => $attempt->id, 'question_id' => $questionId],
            $mapped
        );
    }

    /**
     * Store interactive result (untrusted client score kept separate).
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveInteractiveResult(QuizAttempt $attempt, Question $question, array $payload): QuizAttemptAnswer
    {
        return $this->saveAnswer($attempt, $question->id, [
            'answer' => [
                'result' => $payload['result'] ?? null,
                'interaction_data' => $payload['interaction_data'] ?? null,
            ],
            'client_result' => [
                'completed' => (bool) ($payload['completed'] ?? true),
                'clientScore' => $payload['clientScore'] ?? null,
                'result' => $payload['result'] ?? null,
            ],
        ]);
    }

    /**
     * Convert frontend { answer: {...} } shape into internal answer fields.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeFrontendAnswer(array $payload): array
    {
        if (isset($payload['answer']) && is_array($payload['answer'])) {
            $answer = $payload['answer'];
            $out = [];

            if (isset($answer['option_id'])) {
                $out['selected_option_ids'] = [(int) $answer['option_id']];
            }
            if (isset($answer['option_ids']) && is_array($answer['option_ids'])) {
                $out['selected_option_ids'] = array_map('intval', $answer['option_ids']);
            }
            if (array_key_exists('text', $answer)) {
                $out['text_answer'] = $answer['text'];
            }
            if (array_key_exists('numeric', $answer)) {
                $out['numeric_answer'] = $answer['numeric'];
            }
            if (isset($answer['matches']) && is_array($answer['matches'])) {
                $out['matching_answer'] = $answer['matches'];
            }
            if (isset($answer['order']) && is_array($answer['order'])) {
                $out['ordering_answer'] = array_map('intval', $answer['order']);
            }
            if (isset($answer['blanks']) && is_array($answer['blanks'])) {
                $out['interactive_answer'] = ['blanks' => $answer['blanks']];
            }
            if (isset($answer['result']) || isset($answer['interaction_data'])) {
                $out['interactive_answer'] = [
                    'answer' => $answer['result'] ?? $answer['interaction_data'] ?? null,
                    'result' => $answer['result'] ?? null,
                    'interaction_data' => $answer['interaction_data'] ?? null,
                ];
                $out['client_result'] = [
                    'result' => $answer['result'] ?? null,
                    'interaction_data' => $answer['interaction_data'] ?? null,
                ];
            }

            if (isset($payload['client_result'])) {
                $out['client_result'] = $payload['client_result'];
            }

            return $out;
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @return array<int, array<string, mixed>>
     */
    public function normalizeAnswersList(array $answers): array
    {
        return array_map(function (array $row) {
            $normalized = $this->normalizeFrontendAnswer($row);

            return array_merge(['question_id' => $row['question_id']], $normalized);
        }, $answers);
    }

    /**
     * @return Collection<int, Question>
     */
    private function resolveQuestionsForNewAttempt(Quiz $quiz): Collection
    {
        if (($quiz->selection_mode ?? QuizSelectionMode::Fixed) === QuizSelectionMode::Generated) {
            return $this->selectionService->selectForQuiz($quiz);
        }

        return $quiz->questions()
            ->with('options')
            ->where(function ($q): void {
                $q->where('status', 'published')->orWhereNull('status');
            })
            ->orderBy('sort_order')
            ->get()
            ->when($quiz->shuffle_questions, fn ($c) => $c->shuffle()->values());
    }

    /**
     * @param  Collection<int, Question>  $questions
     */
    private function freezeQuestions(QuizAttempt $attempt, Collection $questions): void
    {
        foreach ($questions->values() as $index => $question) {
            QuizAttemptQuestion::query()->create([
                'quiz_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mapAnswerPayload(array $payload): array
    {
        return [
            'selected_option_ids' => $payload['selected_option_ids'] ?? null,
            'text_answer' => $payload['text_answer'] ?? null,
            'numeric_answer' => $payload['numeric_answer'] ?? null,
            'matching_answer' => $payload['matching_answer'] ?? null,
            'ordering_answer' => $payload['ordering_answer'] ?? null,
            'interactive_answer' => $payload['interactive_answer'] ?? null,
            'client_result' => $payload['client_result'] ?? null,
        ];
    }
}
