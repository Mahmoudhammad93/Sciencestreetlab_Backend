<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Repositories;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;

interface CategoryRepositoryInterface
{
    public function findActive(): iterable;

    public function findActiveBySlug(string $slug): ?Category;
}
