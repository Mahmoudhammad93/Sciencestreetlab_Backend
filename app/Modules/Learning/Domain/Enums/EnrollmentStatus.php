<?php

declare(strict_types=1);

namespace App\Modules\Learning\Domain\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Suspended = 'suspended';
}
