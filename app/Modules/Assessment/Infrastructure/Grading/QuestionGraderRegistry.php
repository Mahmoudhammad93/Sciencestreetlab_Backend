<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

use App\Modules\Assessment\Domain\Contracts\QuestionGraderInterface;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

final class QuestionGraderRegistry
{
    /** @param  iterable<QuestionGraderInterface>  $graders */
    public function __construct(
        private readonly iterable $graders,
    ) {}

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool
    {
        foreach ($this->graders as $grader) {
            if ($grader->supports($question->question_type)) {
                return $grader->grade($question, $answer);
            }
        }

        return false;
    }
}
