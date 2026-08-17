<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

final class NumericGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::Numeric;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer || $answer->numeric_answer === null) {
            return false;
        }

        $expected = $question->answer_key['value'] ?? null;
        if ($expected === null) {
            return false;
        }

        $tolerance = (float) ($question->answer_key['tolerance'] ?? 0);
        $given = (float) $answer->numeric_answer;
        $target = (float) $expected;

        return abs($given - $target) <= $tolerance;
    }
}
