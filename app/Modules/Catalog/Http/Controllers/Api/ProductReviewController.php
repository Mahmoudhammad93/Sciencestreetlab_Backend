<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\Services\ProductReviewService;
use App\Modules\Catalog\Domain\Repositories\ProductRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductReviewController extends Controller
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductReviewService $reviews,
    ) {}

    public function store(Request $request, string $slug): JsonResponse
    {
        $product = $this->products->findBySlug($slug);
        if ($product === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $review = $this->reviews->submit($product, $request->user(), $data);

        return response()->json([
            'message' => 'Your review has been submitted and is awaiting approval.',
            'data' => [
                'id' => $review->id,
                'status' => $review->status->value,
                'rating' => $review->rating,
                'review' => $review->review,
            ],
        ], 201);
    }
}
