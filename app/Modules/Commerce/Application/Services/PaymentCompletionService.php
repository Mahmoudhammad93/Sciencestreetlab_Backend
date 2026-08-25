<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;

final class PaymentCompletionService
{
    public function complete(Payment $payment, ?string $transactionId = null, ?array $gatewayResponse = null): Payment
    {
        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        $payment->update([
            'transaction_id' => $transactionId ?? ('mock_txn_'.$payment->id),
            'status' => PaymentStatus::Completed->value,
            'paid_at' => now(),
            'gateway_response' => $gatewayResponse ?? ['mode' => 'mock', 'completed_at' => now()->toIso8601String()],
        ]);

        $order = $payment->order;
        $order->update([
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
        ]);

        event(new OrderPaid($order->fresh(['items'])));

        return $payment->fresh();
    }

    public function fail(Payment $payment, ?array $gatewayResponse = null): Payment
    {
        if ($payment->status === PaymentStatus::Completed->value) {
            return $payment;
        }

        $payment->update([
            'status' => PaymentStatus::Failed->value,
            'gateway_response' => $gatewayResponse,
        ]);

        $payment->order->update(['status' => OrderStatus::Pending->value]);

        return $payment->fresh();
    }
}
