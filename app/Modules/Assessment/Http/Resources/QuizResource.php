<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quiz */
final class QuizResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Quiz $quiz */
        $quiz = $this->resource;
        $locale = app()->getLocale();
        $quiz->loadMissing('interactiveActivities');

        return [
            'id' => $quiz->id,
            'uuid' => $quiz->uuid,
            'title' => $quiz->getTranslation('title', $locale),
            'instructions' => $quiz->getTranslation('instructions', $locale),
            'passing_score' => (float) $quiz->passing_score,
            'max_attempts' => $quiz->max_attempts,
            'time_limit_seconds' => $quiz->time_limit_seconds,
            'selection_mode' => $quiz->selection_mode?->value ?? 'fixed',
            'shuffle_questions' => (bool) $quiz->shuffle_questions,
            'is_required' => (bool) $quiz->is_required,
            'question_count' => $quiz->isGenerated()
                ? (int) ($quiz->selection_config['total_questions']
                    ?? array_sum($quiz->selection_config['difficulty'] ?? []))
                : $quiz->questions()->count(),
            'interactive_activities' => $quiz->interactiveActivities->map(
                fn ($a) => (new InteractiveActivityResource($a))->toArray($request)
            )->values(),
        ];
    }
}
