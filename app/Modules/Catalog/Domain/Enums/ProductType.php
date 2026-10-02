<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum ProductType: string
{
    case Kit = 'kit';
    case Course = 'course';
    case Bundle = 'bundle';
}
