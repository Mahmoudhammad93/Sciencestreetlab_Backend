<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Services\GuestOrderCapabilityService;
use App\Modules\Commerce\Application\Support\OrderPaymentEligibility;
use App\Modules\Commerce\Http\Support\ResolvesCart;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Shared\Contracts\PaymentGatewayInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly ResolvesCart $resolvesCart,
        private readonly CheckoutService $checkoutService,
        private readonly GuestOrderCapabilityService $guestCapabilities,
        private readonly OrderPaymentEligibility $paymentEligibility,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_address' => ['required', 'array'],
            'billing_address.first_name' => ['required', 'string', 'max:255'],
            'billing_address.last_name' => ['required', 'string', 'max:255'],
            'billing_address.email' => ['required', 'email'],
            'billing_address.phone' => ['required', 'string', 'max:20'],
            'billing_address.city' => ['nullable', 'string', 'max:100'],
            'billing_address.country' => ['nullable', 'string', 'max:2'],
            'billing_address.address' => ['nullable', 'string', 'max:500'],
            'billing_address.district' => ['nullable', 'string', 'max:150'],
            'billing_address.district_name' => ['nullable', 'string', 'max:150'],
            'billing_address.district_id' => ['nullable', 'string', 'max:64'],
            'billing_address.bosta_district_id' => ['nullable', 'string', 'max:64'],
            'billing_address.bosta_city_id' => ['nullable', 'string', 'max:64'],
            'billing_address.zone_id' => ['nullable', 'string', 'max:64'],
            'billing_address.bosta_zone_id' => ['nullable', 'string', 'max:64'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.first_name' => ['nullable', 'string', 'max:255'],
            'shipping_address.last_name' => ['nullable', 'string', 'max:255'],
            'shipping_address.email' => ['nullable', 'email'],
            'shipping_address.phone' => ['nullable', 'string', 'max:20'],
            'shipping_address.city' => ['nullable', 'string', 'max:100'],
            'shipping_address.country' => ['nullable', 'string', 'max:2'],
            'shipping_address.address' => ['nullable', 'string', 'max:500'],
            'shipping_address.district' => ['nullable', 'string', 'max:150'],
            'shipping_address.district_name' => ['nullable', 'string', 'max:150'],
            'shipping_address.district_id' => ['nullable', 'string', 'max:64'],
            'shipping_address.bosta_district_id' => ['nullable', 'string', 'max:64'],
            'shipping_address.bosta_city_id' => ['nullable', 'string', 'max:64'],
            'shipping_address.zone_id' => ['nullable', 'string', 'max:64'],
            'shipping_address.bosta_zone_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $cart = $this->resolvesCart->fromRequest($request);

        try {
            $result = $this->checkoutService->createOrderFromCart(
                $user,
                $cart,
                $validated['billing_address'],
                $validated['shipping_address'] ?? null,
                $validated['notes'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload = [
            'data' => $result['order'],
        ];

        if ($result['guest_tokens'] !== null) {
            $payload['guest'] = [
                'pay_token' => $result['guest_tokens']['pay_token'],
                'access_token' => $result['guest_tokens']['status_token'],
            ];
        }

        return response()->json($payload, 201);
    }

    public function pay(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $guestPayToken = $request->input('guest_pay_token')
            ?? $request->header('X-Guest-Pay-Token');

        $authorized = false;

        if ($user && $order->user_id !== null && (int) $order->user_id === (int) $user->id) {
            $authorized = true;
        }

        if (! $authorized && $order->is_guest && $order->user_id === null) {
            try {
                $this->guestCapabilities->assertPayAllowed($order, is_string($guestPayToken) ? $guestPayToken : null);
                $authorized = true;
            } catch (DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
        }

        if (! $authorized) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->paymentEligibility->assertRetryAllowed($order);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $gateway = app(PaymentGatewayInterface::class);

        try {
            $result = $gateway->initiate($order);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'payment_id' => $result->paymentId,
                'iframe_url' => $result->iframeUrl,
                'payment_url' => $result->iframeUrl,
                'gateway' => config('commerce.payment_gateway'),
                'gateway_order_id' => $result->gatewayOrderId,
            ],
        ]);
    }
}
