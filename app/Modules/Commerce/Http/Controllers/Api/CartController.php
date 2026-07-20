<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\CartService;
use App\Modules\Commerce\Application\Services\CouponService;
use App\Modules\Commerce\Http\Support\ResolvesCart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CouponService $couponService,
        private readonly ResolvesCart $resolvesCart,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->resolvesCart->fromRequest($request);

        $cart->load('items.product', 'coupon');
        $subtotal = $cart->total();
        $discount = $this->couponService->discountForCart($cart);

        return response()->json([
            'data' => [
                'id' => $cart->id,
                'items' => $cart->items,
                'coupon_code' => $cart->coupon_code,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => max(0, $subtotal - $discount),
                'item_count' => $cart->items->sum('quantity'),
            ],
        ]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['integer', 'min:1', 'max:99'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);
        $cart = $this->resolvesCart->fromRequest($request);

        $item = $this->cartService->addItem(
            $cart,
            $product,
            $validated['quantity'] ?? 1
        );

        return response()->json(['data' => $item->load('product')], 201);
    }

    public function updateItem(Request $request, CartItem $item): JsonResponse
    {
        $cart = $this->resolvesCart->fromRequest($request);

        if ($item->cart_id !== $cart->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $updated = $this->cartService->updateQuantity($item, $validated['quantity']);

        return response()->json([
            'data' => $updated,
            'message' => $updated ? 'Updated' : 'Removed',
        ]);
    }

    public function removeItem(Request $request, CartItem $item): JsonResponse
    {
        $cart = $this->resolvesCart->fromRequest($request);

        if ($item->cart_id !== $cart->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->cartService->removeItem($item);

        return response()->json(['message' => 'Removed']);
    }
}
