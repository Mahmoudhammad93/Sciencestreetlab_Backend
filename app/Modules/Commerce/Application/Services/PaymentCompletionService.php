<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use Illuminate\Support\Facades\DB;

final class PaymentCompletionService
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
        private readonly GuestPurchaseClaimService $guestClaims,
        private readonly GuestOrderCapabilityService $guestCapabilities,
    ) {}

    public function complete(Payment $payment, ?string $transactionId = null, ?array $gatewayResponse = null): Payment
    {
        $completed = DB::transaction(function () use ($payment, $transactionId, $gatewayResponse): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Completed->value) {
                return $locked->loadMissing('order');
            }

            $locked->update([
                'transaction_id' => $transactionId ?? ('mock_txn_'.$locked->id),
                'status' => PaymentStatus::Completed->value,
                'paid_at' => now(),
                'gateway_response' => $gatewayResponse ?? ['mode' => 'mock', 'completed_at' => now()->toIso8601String()],
            ]);

            return $locked->fresh(['order']) ?? $locked->load('order');
        });

        if ($completed->order !== null) {
            $this->fulfillment->markPaid($completed->order);
        }

        return $completed->fresh(['order']) ?? $completed;
    }

    public function fail(Payment $payment, ?array $gatewayResponse = null): Payment
    {
        return DB::transaction(function () use ($payment, $gatewayResponse): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Completed->value) {
                return $locked;
            }

            $locked->update([
                'status' => PaymentStatus::Failed->value,
                'gateway_response' => $gatewayResponse,
            ]);

            $locked->loadMissing('order');
            // Failed online attempts remain unpaid and retryable — keep awaiting_payment.
            // Do not move the order into a non-retryable "pending" state that hides Pay Now.
            if ($locked->order !== null && $locked->order->paid_at === null) {
                $status = (string) $locked->order->status;
                if (! in_array($status, [
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                    OrderStatus::Paid->value,
                ], true)) {
                    $locked->order->update(['status' => OrderStatus::AwaitingPayment->value]);
                }
            }

            return $locked->fresh() ?? $locked;
        });
    }

    public function markOrderCancelledOrRefunded(Order $order): void
    {
        $this->guestClaims->revokeForOrder($order);
        $this->guestCapabilities->revokeAllForOrder($order);
    }
}
