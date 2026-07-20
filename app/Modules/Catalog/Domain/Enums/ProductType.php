<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum ProductType: string
{
    case Kit = 'kit';
    case Course = 'course';
    case Bundle = 'bundle';
}

enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
