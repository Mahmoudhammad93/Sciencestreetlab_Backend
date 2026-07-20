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

enum LessonType: string
{
    case Theory = 'theory';
    case Assembly = 'assembly';
    case DesignLab = 'design_lab';
    case CreativityLab = 'creativity_lab';
}

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Suspended = 'suspended';
}
