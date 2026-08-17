<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class QuizAttemptQuestionResource extends JsonResource
{
    public function __construct($resource, private readonly ?QuizAttempt $attempt = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Question $question */
        $question = $this->resource;
        $base = (new StudentQuestionResource($question))->toArray($request);

        if ($this->attempt) {
            $saved = $this->attempt->answers->firstWhere('question_id', $question->id);
            $base['has_answer'] = $saved !== null;
            $base['saved_answer'] = $saved ? [
                'selected_option_ids' => $saved->selected_option_ids,
                'text_answer' => $saved->text_answer,
                'numeric_answer' => $saved->numeric_answer !== null ? (float) $saved->numeric_answer : null,
                'matching_answer' => $saved->matching_answer,
                'ordering_answer' => $saved->ordering_answer,
                'interactive_answer' => $saved->interactive_answer,
            ] : null;
        }

        return $base;
    }
}
