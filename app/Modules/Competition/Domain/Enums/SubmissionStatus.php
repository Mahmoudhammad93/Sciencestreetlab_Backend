<?php

declare(strict_types=1);

namespace App\Modules\Competition\Domain\Enums;

enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RevisionRequested = 'revision_requested';
}
