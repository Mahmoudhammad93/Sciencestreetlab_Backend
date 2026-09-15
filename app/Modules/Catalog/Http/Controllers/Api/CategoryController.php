<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\Services\ProductPresenter;
use App\Modules\Catalog\Domain\Repositories\CategoryRepositoryInterface;
use App\Modules\Catalog\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Http\JsonResponse;

final class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->categories->findActive(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $category = $this->categories->findActiveBySlug($slug);

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json(['data' => $category]);
    }

    public function products(string $slug): JsonResponse
    {
        $category = $this->categories->findActiveBySlug($slug);

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $items = collect($this->products->findPublishedByCategory($category->id))
            ->map(fn (Product $product) => $this->presenter->present($product))
            ->values();

        return response()->json([
            'data' => $items,
        ]);
    }
}
