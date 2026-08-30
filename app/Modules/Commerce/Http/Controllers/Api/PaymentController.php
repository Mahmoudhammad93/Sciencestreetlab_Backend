<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Payment\MyFatoorahGateway;
use App\Modules\Commerce\Infrastructure\Payment\MyFatoorahReturnHandler;
use App\Modules\Commerce\Infrastructure\Payment\PaymobGateway;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Shared\Contracts\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $result = $this->processMyFatoorahReturn($request);

        if ($result['success']) {
            return redirect($this->frontendUrl('/checkout/success?order='.$result['order_number']));
        }

        $failedPath = $result['order_number']
            ? '/checkout/failed?order='.$result['order_number']
            : '/checkout/failed';

        return redirect($this->frontendUrl($failedPath));
    }

    public function myfatoorahConfirm(Request $request): JsonResponse
    {
        $result = $this->processMyFatoorahReturn($request);

        if ($result['success']) {
            return response()->json([
                'data' => [
                    'status' => PaymentStatus::Completed->value,
                    'order_number' => $result['order_number'],
                ],
            ]);
        }

        return response()->json([
            'message' => $result['message'] ?? 'Payment was not completed.',
            'data' => [
                'status' => PaymentStatus::Failed->value,
                'order_number' => $result['order_number'],
            ],
        ], 422);
    }

    /**
     * @return array{success: bool, order_number: ?string, message?: string}
     */
    private function processMyFatoorahReturn(Request $request): array
    {
        /** @var MyFatoorahGateway $gateway */
        $gateway = app(PaymentGatewayInterface::class);

        if (! $gateway instanceof MyFatoorahGateway) {
            abort(400, 'MyFatoorah gateway is not active.');
        }

        $localPaymentId = (int) $request->query('local_payment_id');
        $gatewayPaymentId = MyFatoorahReturnHandler::gatewayPaymentIdFromQuery($request->query());
        $orderNumber = null;

        try {
            $existing = Payment::query()->with('order')->find($localPaymentId);
            $orderNumber = $existing?->order?->order_number;

            $payment = $gateway->handleReturn($localPaymentId, $gatewayPaymentId);
            $orderNumber = $payment->order->order_number;

            if ($payment->status === PaymentStatus::Completed->value) {
                return [
                    'success' => true,
                    'order_number' => $orderNumber,
                ];
            }
        } catch (Throwable $e) {
            Log::warning('MyFatoorah return handling failed', [
                'local_payment_id' => $localPaymentId,
                'gateway_payment_id' => $gatewayPaymentId,
                'query' => $request->query(),
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'order_number' => $orderNumber,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => false,
            'order_number' => $orderNumber,
            'message' => 'Payment was not completed.',
        ];
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
