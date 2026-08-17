<?php

declare(strict_types=1);

namespace App\Modules\Learning\Domain\Enums;

enum AccessType: string
{
    case Paid = 'paid';
    case Free = 'free';
    case School = 'school';
    case Closed = 'closed';
}
