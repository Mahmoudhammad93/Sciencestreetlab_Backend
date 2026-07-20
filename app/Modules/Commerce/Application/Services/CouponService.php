<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon;
use DomainException;

final class CouponService
{
    public function findValid(string $code): Coupon
    {
        $coupon = Coupon::query()
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $coupon) {
            throw new DomainException('Invalid coupon code.');
        }

        if (! $coupon->isValid()) {
            throw new DomainException('Coupon is expired or no longer valid.');
        }

        return $coupon;
    }

    public function applyToCart(Cart $cart, string $code): Cart
    {
        $cart->load('items');
        $subtotal = $cart->total();

        if ($subtotal <= 0) {
            throw new DomainException('Cart is empty.');
        }

        $coupon = $this->findValid($code);

        if ($coupon->min_order_amount && $subtotal < (float) $coupon->min_order_amount) {
            throw new DomainException('Order total does not meet minimum amount for this coupon.');
        }

        $cart->update([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
        ]);

        return $cart->fresh(['items.product', 'coupon']);
    }

    public function removeFromCart(Cart $cart): Cart
    {
        $cart->update(['coupon_id' => null, 'coupon_code' => null]);

        return $cart->fresh(['items.product']);
    }

    public function discountForCart(Cart $cart): float
    {
        if (! $cart->coupon_id) {
            return 0;
        }

        $coupon = $cart->coupon ?? Coupon::query()->find($cart->coupon_id);

        if (! $coupon || ! $coupon->isValid()) {
            return 0;
        }

        return $coupon->calculateDiscount($cart->total());
    }
}
