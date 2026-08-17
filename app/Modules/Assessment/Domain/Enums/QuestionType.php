<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';
    case ShortAnswer = 'short_answer';
    case LongAnswer = 'long_answer';
    case FillBlank = 'fill_blank';
    case Matching = 'matching';
    case Ordering = 'ordering';
    case Numeric = 'numeric';
    case InteractiveHtml = 'interactive_html';
    /** Links a quiz item to a first-class InteractiveActivity package. */
    case InteractiveActivity = 'interactive_activity';
}
