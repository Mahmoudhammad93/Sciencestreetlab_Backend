<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuizAttempt */
final class QuizResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var QuizAttempt $attempt */
        $attempt = $this->resource;
        $attempt->loadMissing('answers');
        $questions = app(QuizAttemptService::class)->questionsForAttempt($attempt);
        $byId = $questions->keyBy('id');
        $allowExplanations = in_array($attempt->status, [AttemptStatus::Graded, AttemptStatus::PendingReview], true);

        return [
            'attempt_id' => $attempt->id,
            'status' => $attempt->status === AttemptStatus::PendingReview
                ? 'pending_review'
                : ($attempt->passed ? 'passed' : 'failed'),
            'score' => (float) $attempt->score,
            'max_score' => (float) $attempt->max_score,
            'percentage' => (float) $attempt->percentage,
            'passed' => $attempt->passed,
            'total_points' => (float) $attempt->max_score,
            'earned_points' => (float) $attempt->score,
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'graded_at' => $attempt->graded_at?->toIso8601String(),
            'question_results' => $attempt->answers->map(function ($answer) use ($request, $byId, $allowExplanations) {
                $question = $byId->get($answer->question_id);

                return [
                    'question_id' => $answer->question_id,
                    'is_correct' => $answer->is_correct,
                    'points_awarded' => $answer->points_awarded !== null ? (float) $answer->points_awarded : null,
                    'needs_manual_review' => (bool) $answer->needs_manual_review,
                    'question' => $question
                        ? (new StudentQuestionResource($question, $allowExplanations))->toArray($request)
                        : null,
                ];
            })->values(),
        ];
    }
}
