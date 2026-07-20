<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enums;

enum UserLocale: string
{
    case Arabic = 'ar';
    case English = 'en';
}
