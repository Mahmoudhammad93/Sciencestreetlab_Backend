<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Payment\MyFatoorahGateway;
use App\Modules\Commerce\Infrastructure\Payment\PaymobGateway;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Shared\Contracts\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    public function paymobCallback(Request $request): JsonResponse
    {
        /** @var PaymobGateway $gateway */
        $gateway = app(PaymentGatewayInterface::class);

        if (! $gateway instanceof PaymobGateway) {
            return response()->json(['message' => 'Paymob gateway is not active.'], 400);
        }

        try {
            $payment = $gateway->handleCallback($request->all());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json(['data' => ['payment_id' => $payment->id, 'status' => $payment->status]]);
    }

    public function myfatoorahCallback(Request $request): RedirectResponse
    {
        /** @var MyFatoorahGateway $gateway */
        $gateway = app(PaymentGatewayInterface::class);

        if (! $gateway instanceof MyFatoorahGateway) {
            abort(400, 'MyFatoorah gateway is not active.');
        }

        $localPaymentId = (int) $request->query('local_payment_id');
        $gatewayPaymentId = $request->query('paymentId');

        try {
            $payment = $gateway->handleReturn($localPaymentId, is_string($gatewayPaymentId) ? $gatewayPaymentId : null);
        } catch (\Throwable) {
            return redirect($this->frontendUrl('/checkout/failed'));
        }

        $orderNumber = $payment->order->order_number;

        if ($payment->status === PaymentStatus::Completed->value) {
            return redirect($this->frontendUrl('/checkout/success?order='.$orderNumber));
        }

        return redirect($this->frontendUrl('/checkout/failed?order='.$orderNumber));
    }

    public function completeMock(Payment $payment, PaymentCompletionService $completion): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            return response()->json(['message' => 'Not available'], 404);
        }

        $payment = $completion->complete($payment);

        return response()->json([
            'data' => $payment->load('order'),
            'message' => 'Mock payment completed.',
        ]);
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('sciencestreet.frontend_url', 'http://localhost:5173'), '/').$path;
    }
}
