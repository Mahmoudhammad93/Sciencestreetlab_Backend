<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';
    case ShortAnswer = 'short_answer';
}

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Graded = 'graded';
    case Abandoned = 'abandoned';
}
