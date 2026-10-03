<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\Services\ProductPresenter;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HomeLatestProductsController extends Controller
{
    public function __construct(
        private readonly ProductPresenter $presenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 3);
        $limit = max(1, min(12, $limit));

        $items = Product::query()
            ->with(['category.media', 'curriculumAlignments', 'relatedCourse', 'media'])
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => $this->presenter->present($product, ['with_reviews' => false]))
            ->values();

        return response()->json(['data' => $items])
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
