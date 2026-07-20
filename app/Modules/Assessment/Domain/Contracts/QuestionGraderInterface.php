<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Contracts;

use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;

interface QuestionGraderInterface
{
    public function supports(QuestionType $type): bool;

    public function grade(Question $question, ?QuizAttemptAnswer $answer): bool;
}
