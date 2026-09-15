<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence;

use App\Modules\Catalog\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Shared\Kernel\BaseRepository;

final class EloquentProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    protected function model(): string
    {
        return Product::class;
    }

    public function findBySlug(string $slug): ?Product
    {
        return $this->query()
            ->with(['category.media', 'curriculumAlignments', 'relatedCourse', 'media'])
            ->where('slug', $slug)
            ->first();
    }

    public function findPublished(): iterable
    {
        return $this->query()
            ->with(['category.media', 'curriculumAlignments', 'relatedCourse', 'media'])
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->get();
    }

    public function findPublishedByCategory(int $categoryId): iterable
    {
        return $this->query()
            ->with(['category.media', 'curriculumAlignments', 'relatedCourse', 'media'])
            ->where('category_id', $categoryId)
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->get();
    }
}
