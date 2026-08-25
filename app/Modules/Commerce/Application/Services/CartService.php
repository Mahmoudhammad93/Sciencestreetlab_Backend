<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use Illuminate\Support\Facades\DB;

final class CartService
{
    public function resolveCart(?User $user, ?string $sessionId): Cart
    {
        if ($user) {
            return Cart::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['expires_at' => now()->addDays(30)]
            );
        }

        return Cart::query()->firstOrCreate(
            ['session_id' => $sessionId],
            ['expires_at' => now()->addDays(7)]
        );
    }

    public function addItem(Cart $cart, Product $product, int $quantity = 1): CartItem
    {
        $item = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        if ($item) {
            $item->update(['quantity' => $item->quantity + $quantity]);

            return $item->fresh();
        }

        return CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->price,
        ]);
    }

    public function updateQuantity(CartItem $item, int $quantity): ?CartItem
    {
        if ($quantity <= 0) {
            $item->delete();

            return null;
        }

        $item->update(['quantity' => $quantity]);

        return $item->fresh();
    }

    public function removeItem(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
    }

    public function mergeSessionCartIntoUserCart(User $user, string $sessionId): Cart
    {
        $userCart = $this->resolveCart($user, null);

        $sessionCart = Cart::query()
            ->where('session_id', $sessionId)
            ->whereNull('user_id')
            ->first();

        if (! $sessionCart || $sessionCart->id === $userCart->id) {
            return $userCart;
        }

        $sessionCart->load('items.product');

        if ($sessionCart->items->isEmpty()) {
            return $userCart;
        }

        return DB::transaction(function () use ($userCart, $sessionCart): Cart {
            foreach ($sessionCart->items as $item) {
                if ($item->product) {
                    $this->addItem($userCart, $item->product, $item->quantity);
                }
            }

            if ($sessionCart->coupon_id && ! $userCart->coupon_id) {
                $userCart->update([
                    'coupon_id' => $sessionCart->coupon_id,
                    'coupon_code' => $sessionCart->coupon_code,
                ]);
            }

            $this->clear($sessionCart);
            $sessionCart->delete();

            return $userCart->fresh(['items']);
        });
    }
}
