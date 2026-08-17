<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Graded = 'graded';
    case PendingReview = 'pending_review';
    case Abandoned = 'abandoned';
}
