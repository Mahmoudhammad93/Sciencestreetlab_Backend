<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Wishlist::query()
            ->where('user_id', $request->user()->id)
            ->with('product')
            ->latest()
            ->get();

        return response()->json(['data' => $items]);
    }

    public function toggle(Request $request, Product $product): JsonResponse
    {
        $existing = Wishlist::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return response()->json(['data' => ['in_wishlist' => false]]);
        }

        Wishlist::query()->create([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        return response()->json(['data' => ['in_wishlist' => true]], 201);
    }
}
