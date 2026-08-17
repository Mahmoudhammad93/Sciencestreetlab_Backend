<?php

declare(strict_types=1);

namespace App\Modules\Learning\Domain\Enums;

enum LessonType: string
{
    case Theory = 'theory';
    case Assembly = 'assembly';
    case DesignLab = 'design_lab';
    case CreativityLab = 'creativity_lab';
}
