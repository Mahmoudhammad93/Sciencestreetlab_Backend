<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Repositories\ProductRepositoryInterface;
use Illuminate\Http\JsonResponse;

final class ProductController extends Controller
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->products->findPublished(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = $this->products->findBySlug($slug);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json(['data' => $product]);
    }
}
