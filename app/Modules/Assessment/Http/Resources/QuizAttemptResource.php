<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuizAttempt */
final class QuizAttemptResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $includeQuestions = true,
        private readonly bool $includeSavedAnswers = true,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var QuizAttempt $attempt */
        $attempt = $this->resource;
        $attempt->loadMissing(['quiz', 'answers', 'quiz.interactiveActivities']);

        $questions = app(QuizAttemptService::class)->questionsForAttempt($attempt);
        $activityCount = $attempt->quiz?->interactiveActivities?->count() ?? 0;
        $totalItems = $questions->count() + $activityCount;
        $expiresAt = $this->expiresAt($attempt);
        $remaining = null;
        if ($expiresAt) {
            $remaining = max(0, $expiresAt->getTimestamp() - now()->getTimestamp());
        }

        $answeredCount = $attempt->answers->filter(function ($a) {
            return filled($a->selected_option_ids)
                || filled($a->text_answer)
                || $a->numeric_answer !== null
                || filled($a->matching_answer)
                || filled($a->ordering_answer)
                || filled($a->interactive_answer);
        })->count();

        $payload = [
            'attempt_id' => $attempt->id,
            'id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'status' => $attempt->status->value,
            'attempt_number' => $attempt->attempt_number,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'remaining_seconds' => $remaining,
            'total_questions' => $totalItems,
            'answered_count' => $answeredCount,
            'progress' => [
                'answered' => $answeredCount,
                'total' => $totalItems,
                'percent' => $totalItems > 0
                    ? round(($answeredCount / $totalItems) * 100, 2)
                    : 0,
            ],
        ];

        if ($this->includeQuestions) {
            $payload['questions'] = $questions->map(
                fn ($q) => (new QuizAttemptQuestionResource($q, $attempt))->toArray($request)
            )->values();

            $payload['interactive_activities'] = ($attempt->quiz?->interactiveActivities ?? collect())
                ->map(function ($activity) use ($request) {
                    $row = (new InteractiveActivityResource($activity))->toArray($request);
                    $row['sort_order'] = (int) ($activity->pivot->sort_order ?? 0);
                    $row['quiz_points'] = (float) ($activity->pivot->points ?? $activity->points);

                    return $row;
                })
                ->values();
        }

        if ($this->includeSavedAnswers && $attempt->status === AttemptStatus::InProgress) {
            $payload['answers'] = $attempt->answers->map(fn ($a) => [
                'question_id' => $a->question_id,
                'answer' => $this->toFrontendAnswer($a),
                'selected_option_ids' => $a->selected_option_ids,
                'text_answer' => $a->text_answer,
                'numeric_answer' => $a->numeric_answer !== null ? (float) $a->numeric_answer : null,
                'matching_answer' => $a->matching_answer,
                'ordering_answer' => $a->ordering_answer,
                'interactive_answer' => $a->interactive_answer,
            ])->values();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function toFrontendAnswer($answer): array
    {
        $out = [];
        $ids = $answer->selected_option_ids;
        if (is_array($ids) && count($ids) === 1) {
            $out['option_id'] = (int) $ids[0];
        }
        if (is_array($ids) && count($ids) > 1) {
            $out['option_ids'] = array_map('intval', $ids);
        }
        if ($answer->text_answer !== null && $answer->text_answer !== '') {
            $out['text'] = $answer->text_answer;
        }
        if ($answer->numeric_answer !== null) {
            $out['numeric'] = (float) $answer->numeric_answer;
        }
        if (is_array($answer->matching_answer)) {
            $out['matches'] = $answer->matching_answer;
        }
        if (is_array($answer->ordering_answer)) {
            $out['order'] = $answer->ordering_answer;
        }
        if (is_array($answer->interactive_answer)) {
            $out['result'] = $answer->interactive_answer['result']
                ?? $answer->interactive_answer['answer']
                ?? $answer->interactive_answer;
            if (isset($answer->interactive_answer['interaction_data'])) {
                $out['interaction_data'] = $answer->interactive_answer['interaction_data'];
            }
        }

        return $out;
    }

    private function expiresAt(QuizAttempt $attempt): ?\Illuminate\Support\Carbon
    {
        $limit = $attempt->quiz?->time_limit_seconds;
        if (! $limit || ! $attempt->started_at) {
            return null;
        }

        return $attempt->started_at->copy()->addSeconds((int) $limit);
    }
}
