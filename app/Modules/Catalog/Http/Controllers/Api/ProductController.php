<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\Services\ProductPresenter;
use App\Modules\Catalog\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Http\JsonResponse;

final class ProductController extends Controller
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $items = collect($this->products->findPublished())
            ->map(fn (Product $product) => $this->presenter->present($product, ['with_reviews' => false]))
            ->values();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = $this->products->findBySlug($slug);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json(['data' => $this->presenter->present($product)]);
    }
}
