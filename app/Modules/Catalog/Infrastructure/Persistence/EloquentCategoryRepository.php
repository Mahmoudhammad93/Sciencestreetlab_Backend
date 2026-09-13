<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence;

use App\Modules\Catalog\Domain\Repositories\CategoryRepositoryInterface;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Shared\Kernel\BaseRepository;

final class EloquentCategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    protected function model(): string
    {
        return Category::class;
    }

    public function findActive(): iterable
    {
        return $this->query()
            ->with('media')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findActiveBySlug(string $slug): ?Category
    {
        return $this->query()
            ->with('media')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }
}
