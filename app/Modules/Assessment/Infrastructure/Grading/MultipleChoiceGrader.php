<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

final class MultipleChoiceGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::MultipleChoice;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer || empty($answer->selected_option_ids)) {
            return false;
        }

        $selected = collect($answer->selected_option_ids)->map(fn ($id) => (int) $id)->sort()->values();
        $correct = $question->options()->where('is_correct', true)->pluck('id')->sort()->values();

        return $selected->all() === $correct->all();
    }
}
