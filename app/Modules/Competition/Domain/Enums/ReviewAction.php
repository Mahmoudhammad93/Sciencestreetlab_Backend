<?php

declare(strict_types=1);

namespace App\Modules\Competition\Domain\Enums;

enum ReviewAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case RequestRevision = 'request_revision';
    case Shortlist = 'shortlist';
}
