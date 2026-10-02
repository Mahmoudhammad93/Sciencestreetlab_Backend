<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Support\OrderPaymentEligibility;
use App\Modules\Commerce\Application\Support\OrderShippingPresenter;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderShippingPresenter $shippingPresenter,
        private readonly OrderPaymentEligibility $paymentEligibility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items', 'payment', 'bostaShipment'])
            ->latest()
            ->paginate(15);

        $orders->getCollection()->transform(function (Order $order): array {
            return $this->orderPayload($order);
        });

        return response()->json($orders);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('user_id', $request->user()->id)
            ->with(['items.product', 'payment', 'bostaShipment'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json(['data' => $this->orderPayload($order)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order): array
    {
        $payload = $order->toArray();

        // Never leak raw shipment rows / metadata to customers.
        unset($payload['bosta_shipment'], $payload['shipments'], $payload['confirmation_email_claimed_at']);

        $eligibility = $this->paymentEligibility->forOrder($order);
        $payload['is_paid'] = $eligibility['is_paid'];
        $payload['payment_required'] = $eligibility['payment_required'];
        $payload['payment_retry_allowed'] = $eligibility['payment_retry_allowed'];
        $payload['latest_payment_status'] = $eligibility['latest_payment_status'];

        // Prefer latest payment row for customer-facing payment summary (no secrets).
        $latestPayment = $order->payments()->orderByDesc('id')->first();

        if ($latestPayment !== null) {
            $payload['payment'] = [
                'id' => $latestPayment->id,
                'status' => $latestPayment->status,
                'gateway' => $latestPayment->gateway,
                'amount' => $latestPayment->amount,
                'currency' => $latestPayment->currency,
                'paid_at' => $latestPayment->paid_at,
            ];
        } else {
            $payload['payment'] = null;
        }

        $payload['shipping'] = $this->shippingPresenter->present($order);

        return $payload;
    }
}
