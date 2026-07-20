<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items', 'payment'])
            ->latest()
            ->paginate(15);

        return response()->json($orders);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('user_id', $request->user()->id)
            ->with(['items.product', 'payment'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json(['data' => $order]);
    }
}
