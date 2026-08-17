<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

enum QuestionBankStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
