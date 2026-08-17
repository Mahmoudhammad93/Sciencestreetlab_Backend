<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum QuizSelectionMode: string
{
    case Fixed = 'fixed';
    case Generated = 'generated';
}
