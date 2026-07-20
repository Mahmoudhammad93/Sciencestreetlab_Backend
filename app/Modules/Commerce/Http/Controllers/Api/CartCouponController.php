<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Services\CouponService;
use App\Modules\Commerce\Http\Support\ResolvesCart;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartCouponController extends Controller
{
    public function __construct(
        private readonly ResolvesCart $resolvesCart,
        private readonly CouponService $couponService,
    ) {}

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ]);

        $cart = $this->resolvesCart->fromRequest($request);

        try {
            $cart = $this->couponService->applyToCart($cart, $validated['code']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->formatCart($cart)]);
    }

    public function remove(Request $request): JsonResponse
    {
        $cart = $this->resolvesCart->fromRequest($request);

        $cart = $this->couponService->removeFromCart($cart);

        return response()->json(['data' => $this->formatCart($cart)]);
    }

    private function formatCart(\App\Modules\Commerce\Infrastructure\Persistence\Models\Cart $cart): array
    {
        $cart->load('items.product', 'coupon');
        $subtotal = $cart->total();
        $discount = $this->couponService->discountForCart($cart);

        return [
            'id' => $cart->id,
            'items' => $cart->items,
            'coupon_code' => $cart->coupon_code,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => max(0, $subtotal - $discount),
            'item_count' => $cart->items->sum('quantity'),
        ];
    }
}
