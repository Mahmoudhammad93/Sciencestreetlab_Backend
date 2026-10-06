<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Support\MicroscopePurchaseOptions;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\CartItem;
use DomainException;
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

    /**
     * @param  array<string, mixed>  $options  e.g. ['book_language' => 'ar']
     */
    public function addItem(Cart $cart, Product $product, int $quantity = 1, array $options = []): CartItem
    {
        $metadata = MicroscopePurchaseOptions::validateAndNormalize($product, $options);
        $optionsKey = MicroscopePurchaseOptions::optionsKey($metadata);

        $item = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->where('options_key', $optionsKey)
            ->first();

        if ($item) {
            $item->update(['quantity' => $item->quantity + $quantity]);

            return $item->fresh(['product']) ?? $item;
        }

        // Upgrade legacy microscope lines that have no book_language instead of
        // leaving an invalid duplicate that the drawer still flags as "required".
        if (
            MicroscopePurchaseOptions::isMicroscopeProduct($product)
            && $optionsKey !== ''
        ) {
            $legacy = $this->findLegacyMicroscopeLineWithoutLanguage($cart, $product);
            if ($legacy !== null) {
                $legacy->update([
                    'quantity' => $legacy->quantity + $quantity,
                    'unit_price' => $product->price,
                    'metadata' => $metadata,
                    'options_key' => $optionsKey,
                ]);

                $this->deleteOtherLegacyMicroscopeLinesWithoutLanguage($cart, $product, $legacy->id);

                return $legacy->fresh(['product']) ?? $legacy;
            }
        }

        return CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->price,
            'metadata' => $metadata === [] ? null : $metadata,
            'options_key' => $optionsKey,
        ])->load('product');
    }

    private function findLegacyMicroscopeLineWithoutLanguage(Cart $cart, Product $product): ?CartItem
    {
        return CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->where(function ($query): void {
                $query->where('options_key', '')
                    ->orWhereNull('options_key');
            })
            ->orderBy('id')
            ->get()
            ->first(function (CartItem $row): bool {
                return MicroscopePurchaseOptions::bookLanguageFromMetadata(
                    is_array($row->metadata) ? $row->metadata : null
                ) === null;
            });
    }

    private function deleteOtherLegacyMicroscopeLinesWithoutLanguage(
        Cart $cart,
        Product $product,
        int $keepId,
    ): void {
        CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->where('id', '!=', $keepId)
            ->where(function ($query): void {
                $query->where('options_key', '')
                    ->orWhereNull('options_key');
            })
            ->get()
            ->each(function (CartItem $row): void {
                if (MicroscopePurchaseOptions::bookLanguageFromMetadata(
                    is_array($row->metadata) ? $row->metadata : null
                ) === null) {
                    $row->delete();
                }
            });
    }

    public function updateQuantity(CartItem $item, int $quantity): ?CartItem
    {
        if ($quantity <= 0) {
            $item->delete();

            return null;
        }

        $item->update(['quantity' => $quantity]);

        return $item->fresh(['product']);
    }

    public function removeItem(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
    }

    /**
     * Block checkout when microscope lines lack a valid book_language.
     */
    public function assertMicroscopeOptionsValid(Cart $cart): void
    {
        $cart->loadMissing('items.product');

        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product instanceof Product || ! MicroscopePurchaseOptions::isMicroscopeProduct($product)) {
                continue;
            }

            $lang = MicroscopePurchaseOptions::bookLanguageFromMetadata(
                is_array($item->metadata) ? $item->metadata : null
            );

            if ($lang === null) {
                throw new DomainException('Book language is required for the microscope.');
            }
        }
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
                if (! $item->product) {
                    continue;
                }

                $options = is_array($item->metadata) ? $item->metadata : [];
                $this->addItem($userCart, $item->product, $item->quantity, $options);
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
