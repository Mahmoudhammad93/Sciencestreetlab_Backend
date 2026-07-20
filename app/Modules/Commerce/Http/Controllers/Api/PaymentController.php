<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Infrastructure\Payment\PaymobGateway;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Shared\Contracts\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    public function paymobCallback(Request $request): JsonResponse
    {
        /** @var PaymobGateway $gateway */
        $gateway = app(PaymentGatewayInterface::class);

        try {
            $payment = $gateway->handleCallback($request->all());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['data' => ['payment_id' => $payment->id, 'status' => $payment->status]]);
    }

    public function completeMock(Payment $payment): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            return response()->json(['message' => 'Not available'], 404);
        }

        /** @var PaymobGateway $gateway */
        $gateway = app(PaymentGatewayInterface::class);
        $payment = $gateway->completeMockPayment($payment);

        return response()->json([
            'data' => $payment->load('order'),
            'message' => 'Mock payment completed.',
        ]);
    }
}
