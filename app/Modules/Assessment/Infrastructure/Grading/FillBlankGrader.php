<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

final class FillBlankGrader implements QuestionGraderInterface
{
    public function supports(QuestionType $type): bool
    {
        return $type === QuestionType::FillBlank;
    }

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        if (! $answer) {
            return false;
        }

        $blanks = $question->answer_key['blanks'] ?? null;

        // Single blank via text_answer
        if (! is_array($blanks)) {
            $accepted = AnswerNormalizer::acceptedList($question->answer_key['accepted'] ?? []);
            if ($accepted === [] || blank($answer->text_answer)) {
                return false;
            }

            return in_array(AnswerNormalizer::text($answer->text_answer), $accepted, true);
        }

        // Multiple blanks: interactive_answer or matching_answer style map blank_key => value
        $given = $answer->interactive_answer['blanks'] ?? $answer->matching_answer ?? [];
        if (! is_array($given) || $given === []) {
            return false;
        }

        foreach ($blanks as $key => $accepted) {
            $value = $given[$key] ?? $given[(string) $key] ?? null;
            $acceptedList = AnswerNormalizer::acceptedList(is_array($accepted) ? $accepted : [$accepted]);
            if ($acceptedList === [] || ! in_array(AnswerNormalizer::text((string) $value), $acceptedList, true)) {
                return false;
            }
        }

        return true;
    }
}
