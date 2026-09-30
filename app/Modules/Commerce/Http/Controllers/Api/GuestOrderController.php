<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Services\GuestOrderCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class GuestOrderController extends Controller
{
    public function __construct(
        private readonly GuestOrderCapabilityService $capabilities,
    ) {}

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $limiterKey = 'guest-order-status|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 30)) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }
        RateLimiter::hit($limiterKey, 60);

        $token = $request->query('token') ?? $request->header('X-Guest-Access-Token');
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Not found'], 404);
        }

        $order = $this->capabilities->findOrderByStatusToken($orderNumber, $token);
        if (! $order) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'data' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => $order->total,
                'currency' => $order->currency,
                'is_guest' => (bool) $order->is_guest,
                'paid_at' => optional($order->paid_at)?->toIso8601String(),
                'fulfilled_at' => optional($order->fulfilled_at)?->toIso8601String(),
                'requires_delivery_fulfillment' => (bool) $order->requires_delivery_fulfillment,
                'items' => $order->items->map(fn ($item) => [
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'total_price' => $item->total_price,
                    'product_type' => $item->metadata['product_type'] ?? null,
                ])->values(),
            ],
        ]);
    }
}
