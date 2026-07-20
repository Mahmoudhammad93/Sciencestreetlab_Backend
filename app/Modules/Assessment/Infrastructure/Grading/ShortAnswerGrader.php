<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

final class ShortAnswerGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::ShortAnswer;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer || blank($answer->text_answer)) {
            return false;
        }

        // Manual grading placeholder: accept non-empty for auto-grade in dev
        return strlen(trim($answer->text_answer)) >= 2;
    }
}
