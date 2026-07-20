<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Services\CartService;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Http\Support\ResolvesCart;
use App\Modules\Commerce\Application\Services\CouponService;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly ResolvesCart $resolvesCart,
        private readonly CheckoutService $checkoutService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_address' => ['required', 'array'],
            'billing_address.first_name' => ['required', 'string', 'max:255'],
            'billing_address.email' => ['required', 'email'],
            'billing_address.phone' => ['required', 'string', 'max:20'],
            'billing_address.city' => ['required', 'string', 'max:100'],
            'billing_address.country' => ['required', 'string', 'max:2'],
            'shipping_address' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $cart = $this->resolvesCart->fromRequest($request);

        try {
            $order = $this->checkoutService->createOrderFromCart(
                $user,
                $cart,
                $validated['billing_address'],
                $validated['shipping_address'],
                $validated['notes'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $order], 201);
    }

    public function pay(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($order->status !== 'awaiting_payment') {
            return response()->json(['message' => 'Order is not awaiting payment.'], 422);
        }

        $gateway = app(\App\Shared\Contracts\PaymentGatewayInterface::class);
        $result = $gateway->initiate($order);

        return response()->json([
            'data' => [
                'payment_id' => $result->paymentId,
                'iframe_url' => $result->iframeUrl,
                'gateway_order_id' => $result->gatewayOrderId,
            ],
        ]);
    }
}
