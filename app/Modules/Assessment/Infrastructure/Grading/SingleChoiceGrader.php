<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

final class SingleChoiceGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::SingleChoice || $type === QuestionType::TrueFalse;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer || empty($answer->selected_option_ids)) {
            return false;
        }

        $selected = (int) $answer->selected_option_ids[0];
        $correct = $question->options()->where('is_correct', true)->pluck('id');

        return $correct->contains($selected);
    }
}
