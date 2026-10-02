<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Support\OrderPaymentMethod;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CheckoutService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CouponService $couponService,
        private readonly BostaShipmentService $bostaShipments,
        private readonly GuestOrderCapabilityService $guestCapabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     * @return array{order: Order, guest_tokens: array{pay_token: string, status_token: string}|null}
     */
    public function createOrderFromCart(
        ?User $user,
        Cart $cart,
        array $billingAddress,
        ?array $shippingAddress = null,
        ?string $notes = null,
        PaymentMethod $paymentMethod = PaymentMethod::Online,
    ): array {
        $cart->load('items.product');

        if ($cart->items->isEmpty()) {
            throw new DomainException('Cannot checkout with an empty cart.');
        }

        $this->assertBilling($billingAddress);

        $requiresShipping = $this->cartRequiresShipping($cart);
        if ($requiresShipping) {
            if ($shippingAddress === null || $shippingAddress === []) {
                throw new DomainException('Shipping address is required for physical products.');
            }
            // Prefer explicit shipping fields; fall back to billing contact when omitted.
            $shippingAddress = array_merge([
                'first_name' => $billingAddress['first_name'] ?? '',
                'last_name' => $billingAddress['last_name'] ?? '',
                'email' => $billingAddress['email'] ?? '',
                'phone' => $billingAddress['phone'] ?? '',
                'address' => $billingAddress['address'] ?? '',
            ], $shippingAddress);
            $this->assertShipping($shippingAddress);
        } else {
            $shippingAddress = $shippingAddress ?: [
                'first_name' => $billingAddress['first_name'] ?? '',
                'last_name' => $billingAddress['last_name'] ?? '',
                'email' => $billingAddress['email'] ?? '',
                'phone' => $billingAddress['phone'] ?? '',
                'city' => $billingAddress['city'] ?? '',
                'country' => $billingAddress['country'] ?? 'EG',
                'address' => $billingAddress['address'] ?? '',
            ];
        }

        $isCod = $paymentMethod->isCashOnDelivery();

        $result = DB::transaction(function () use ($user, $cart, $billingAddress, $shippingAddress, $notes, $isCod): array {
            $lines = [];
            $subtotal = 0.0;

            foreach ($cart->items as $item) {
                $product = $item->product;
                if (! $product instanceof Product) {
                    throw new DomainException('Cart contains an invalid product.');
                }

                $this->assertProductPurchasable($product);

                $unitPrice = (float) $product->price;
                $qty = max(1, (int) $item->quantity);
                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $lines[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                ];
            }

            // Refresh coupon calc against authoritative line prices by syncing cart item prices.
            foreach ($lines as $line) {
                $cart->items
                    ->firstWhere('product_id', $line['product']->id)
                    ?->update(['unit_price' => $line['unit_price']]);
            }
            $cart->unsetRelation('items');
            $cart->load('items.product');

            $discount = $this->couponService->discountForCart($cart);
            $shipping = 0;
            $total = max(0, $subtotal - $discount + $shipping);
            $isGuest = $user === null;

            // COD is confirmed at checkout (not awaiting online payment, not financially paid).
            $orderStatus = $isCod
                ? OrderStatus::Processing->value
                : OrderStatus::AwaitingPayment->value;

            $order = Order::query()->create([
                'user_id' => $user?->id,
                'is_guest' => $isGuest,
                'status' => $orderStatus,
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

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->getTranslation('name', app()->getLocale()) ?: $product->sku,
                    'product_sku' => $product->sku,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                    'metadata' => [
                        'product_type' => $product->type->value,
                        'course_id' => $product->course_id,
                        'course_plan_id' => $product->course_plan_id,
                    ],
                ]);
            }

            if ($isCod) {
                Payment::query()->create([
                    'order_id' => $order->id,
                    'gateway' => OrderPaymentMethod::COD_GATEWAY,
                    'amount' => $order->total,
                    'currency' => $order->currency,
                    'status' => PaymentStatus::Pending->value,
                    'payment_method' => PaymentMethod::CashOnDelivery->value,
                    'paid_at' => null,
                ]);
            }

            if ($cart->coupon_id) {
                Coupon::query()->whereKey($cart->coupon_id)->increment('used_count');
            }

            $this->cartService->clear($cart);
            $cart->update(['coupon_id' => null, 'coupon_code' => null]);

            $order = $order->load(['items', 'payment']);
            // Flag physical kit/bundle orders for delivery gating.
            // Online: external Bosta create happens AFTER verified payment (OrderPaid).
            // COD: external Bosta create runs after this transaction commits.
            $order = $this->bostaShipments->markRequiresDeliveryIfNeeded($order);

            $guestTokens = null;
            if ($isGuest) {
                $guestTokens = $this->guestCapabilities->issueForGuestOrder($order);
            }

            return [
                'order' => $order,
                'guest_tokens' => $guestTokens,
                'is_cod' => $isCod,
            ];
        });

        if ($result['is_cod']) {
            try {
                $this->bostaShipments->ensureShipmentForOrder(
                    $result['order']->fresh(['items.product', 'payment', 'bostaShipment']) ?? $result['order']
                );
                $result['order'] = $result['order']->fresh(['items', 'payment', 'bostaShipment']) ?? $result['order'];
            } catch (Throwable $e) {
                Log::error('COD checkout Bosta shipment ensure failed', [
                    'order_id' => $result['order']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'order' => $result['order'],
            'guest_tokens' => $result['guest_tokens'],
        ];
    }

    public function cartRequiresShipping(Cart $cart): bool
    {
        $cart->loadMissing('items.product');

        foreach ($cart->items as $item) {
            $type = $item->product?->type;
            if ($type === ProductType::Kit || $type === ProductType::Bundle) {
                return true;
            }
        }

        return false;
    }

    private function assertProductPurchasable(Product $product): void
    {
        $status = is_object($product->status) && property_exists($product->status, 'value')
            ? (string) $product->status->value
            : (string) $product->status;

        if ($status !== 'published') {
            throw new DomainException('Product is not available for purchase: '.$product->sku);
        }

        if ($product->manage_stock && (int) ($product->stock_quantity ?? 0) <= 0) {
            throw new DomainException('Product is out of stock: '.$product->sku);
        }
    }

    /**
     * @param  array<string, mixed>  $billing
     */
    private function assertBilling(array $billing): void
    {
        foreach (['first_name', 'last_name', 'email', 'phone'] as $field) {
            if (! isset($billing[$field]) || trim((string) $billing[$field]) === '') {
                throw new DomainException("Billing {$field} is required.");
            }
        }

        if (! filter_var((string) $billing['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Billing email is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $shipping
     */
    private function assertShipping(array $shipping): void
    {
        foreach (['first_name', 'phone', 'city', 'country', 'address'] as $field) {
            if (! isset($shipping[$field]) || trim((string) $shipping[$field]) === '') {
                throw new DomainException("Shipping {$field} is required.");
            }
        }

        if ((bool) config('bosta.enabled')) {
            $districtId = trim((string) (
                $shipping['bosta_district_id']
                ?? $shipping['district_id']
                ?? ''
            ));
            $districtName = trim((string) (
                $shipping['district_name']
                ?? $shipping['district']
                ?? ''
            ));
            if ($districtId === '' && $districtName === '') {
                throw new DomainException(
                    'Shipping district/area is required for physical delivery.'
                );
            }
        }
    }
}
