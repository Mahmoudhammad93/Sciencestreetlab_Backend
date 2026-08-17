<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum InteractiveActivityAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
}
