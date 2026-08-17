<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum QuestionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
