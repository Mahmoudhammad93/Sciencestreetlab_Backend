<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

/**
 * ordering_answer: ordered list of option IDs. Correct order = options sorted by sort_order.
 */
final class OrderingGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::Ordering;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer || ! is_array($answer->ordering_answer) || $answer->ordering_answer === []) {
            return false;
        }

        $question->loadMissing('options');
        $correct = $question->options->sortBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $given = array_map('intval', $answer->ordering_answer);

        return $correct === $given;
    }
}
