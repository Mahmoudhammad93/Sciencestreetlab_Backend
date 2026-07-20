<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Repositories;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;

interface ProductRepositoryInterface
{
    public function findBySlug(string $slug): ?Product;

    public function findPublished(): iterable;
}
