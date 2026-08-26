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
    /** @deprecated Interactive HTML is learning content, not a quiz question. Kept for legacy rows. */
    case InteractiveHtml = 'interactive_html';
    /** @deprecated Interactive activities are topics, not question-bank items. Kept for legacy rows. */
    case InteractiveActivity = 'interactive_activity';

    public function isAssessmentQuestion(): bool
    {
        return ! in_array($this, [self::InteractiveHtml, self::InteractiveActivity], true);
    }

    /**
     * @return list<self>
     */
    public static function assessmentCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type) => $type->isAssessmentQuestion()
        ));
    }
}
