<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum InteractiveActivityStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
