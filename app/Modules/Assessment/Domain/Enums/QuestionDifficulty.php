<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum QuestionDifficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';
}
