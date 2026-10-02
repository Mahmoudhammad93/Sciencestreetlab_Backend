<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Application\Support\OrderPaymentEligibility;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer-initiated order cancellation.
 *
 * State transition only — never deletes the order, never refunds Fawaterak,
 * never creates/cancels Bosta deliveries.
 */
final class CustomerOrderCancelService
{
    /** @var list<string> */
    private const CUSTOMER_CANCELLABLE = [
        OrderStatus::Pending->value,
        OrderStatus::AwaitingPayment->value,
    ];

    public function __construct(
        private readonly OrderPaymentEligibility $paymentEligibility,
    ) {}

    public function customerMayCancel(Order $order): bool
    {
        if ($order->cancelled_at !== null || $order->status === OrderStatus::Cancelled->value) {
            return false;
        }

        $eligibility = $this->paymentEligibility->forOrder($order);
        if ($eligibility['is_paid'] || $order->paid_at !== null) {
            return false;
        }

        if (! in_array($order->status, self::CUSTOMER_CANCELLABLE, true)) {
            return false;
        }

        return true;
    }

    /**
     * Cancel an unpaid order owned by the given user id.
     * Idempotent when already cancelled by the same owner.
     */
    public function cancelForCustomer(Order $order, int $userId, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $userId, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->user_id !== $userId) {
                throw new DomainException('Order not found.');
            }

            if ($locked->status === OrderStatus::Cancelled->value || $locked->cancelled_at !== null) {
                return $locked->fresh(['items', 'payment', 'bostaShipment']) ?? $locked;
            }

            $eligibility = $this->paymentEligibility->forOrder($locked);
            if ($eligibility['is_paid'] || $locked->paid_at !== null) {
                throw new DomainException(
                    'Paid orders cannot be cancelled by the customer. Contact support for refund assistance.'
                );
            }

            if (! in_array($locked->status, self::CUSTOMER_CANCELLABLE, true)) {
                throw new DomainException('This order cannot be cancelled in its current state.');
            }

            // Re-check payment race after lock: a concurrent webhook may have completed payment.
            $locked->refresh();
            $eligibility = $this->paymentEligibility->forOrder($locked);
            if ($eligibility['is_paid'] || $locked->paid_at !== null) {
                throw new DomainException(
                    'Payment completed while cancelling. Contact support if you need assistance.'
                );
            }

            $notes = (string) ($locked->notes ?? '');
            $trimmedReason = $reason !== null ? trim($reason) : '';
            if ($trimmedReason !== '') {
                $annotation = '[customer_cancel] '.$trimmedReason;
                $notes = $notes === '' ? $annotation : $notes."\n".$annotation;
                // Cap notes length to column-friendly size.
                if (mb_strlen($notes) > 1000) {
                    $notes = mb_substr($notes, 0, 1000);
                }
            }

            $locked->update([
                'status' => OrderStatus::Cancelled->value,
                'cancelled_at' => now(),
                'notes' => $notes !== '' ? $notes : $locked->notes,
            ]);

            Log::info('Customer cancelled unpaid order', [
                'order_id' => $locked->id,
                'order_number' => $locked->order_number,
                'user_id' => $userId,
                'has_reason' => $trimmedReason !== '',
            ]);

            return $locked->fresh(['items', 'payment', 'bostaShipment']) ?? $locked;
        });
    }
}
