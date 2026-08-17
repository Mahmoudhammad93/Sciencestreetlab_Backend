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

        $accepted = AnswerNormalizer::acceptedList($question->answer_key['accepted'] ?? $question->answer_key ?? []);

        if ($accepted === []) {
            return false;
        }

        $given = AnswerNormalizer::text($answer->text_answer);

        return in_array($given, $accepted, true);
    }
}
