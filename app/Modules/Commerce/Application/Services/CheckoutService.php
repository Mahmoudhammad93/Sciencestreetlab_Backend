<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CheckoutService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CouponService $couponService,
    ) {}

    /**
     * @param  array<string, mixed>  $billingAddress
     * @param  array<string, mixed>  $shippingAddress
     */
    public function createOrderFromCart(
        User $user,
        Cart $cart,
        array $billingAddress,
        array $shippingAddress,
        ?string $notes = null,
    ): Order {
        $cart->load('items.product');

        if ($cart->items->isEmpty()) {
            throw new DomainException('Cannot checkout with an empty cart.');
        }

        return DB::transaction(function () use ($user, $cart, $billingAddress, $shippingAddress, $notes): Order {
            $subtotal = $cart->total();
            $discount = $this->couponService->discountForCart($cart);
            $shipping = 0;
            $total = max(0, $subtotal - $discount + $shipping);

            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => OrderStatus::AwaitingPayment->value,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'shipping_amount' => $shipping,
                'tax_amount' => 0,
                'total' => $total,
                'currency' => 'EGP',
                'coupon_id' => $cart->coupon_id,
                'coupon_code' => $cart->coupon_code,
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'notes' => $notes,
            ]);

            foreach ($cart->items as $item) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->getTranslation('name', app()->getLocale()) ?: $item->product->sku,
                    'product_sku' => $item->product->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->unit_price * $item->quantity,
                    'metadata' => [
                        'product_type' => $item->product->type->value,
                        'course_id' => $item->product->course_id,
                    ],
                ]);
            }

            if ($cart->coupon_id) {
                Coupon::query()->whereKey($cart->coupon_id)->increment('used_count');
            }

            $this->cartService->clear($cart);
            $cart->update(['coupon_id' => null, 'coupon_code' => null]);

            return $order->load('items');
        });
    }
}
