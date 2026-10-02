<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Application\Support\OrderPaymentEligibility;
use App\Modules\Commerce\Application\Support\OrderPaymentMethod;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer-initiated order cancellation.
 *
 * State transition only — never deletes the order, never refunds Fawaterak,
 * never creates/cancels Bosta deliveries.
 *
 * COD: cancel allowed only before an external Bosta shipment exists.
 * Once external_shipment_id is set, customer auto-cancel is blocked
 * (admin/support must cancel the live Bosta delivery — cancel API not wired).
 */
final class CustomerOrderCancelService
{
    /** @var list<string> */
    private const ONLINE_CANCELLABLE = [
        OrderStatus::Pending->value,
        OrderStatus::AwaitingPayment->value,
    ];

    /** @var list<string> */
    private const COD_CANCELLABLE_WITHOUT_EXTERNAL_SHIPMENT = [
        OrderStatus::Pending->value,
        OrderStatus::AwaitingPayment->value,
        OrderStatus::Processing->value,
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

        if ($this->hasExternalBostaShipment($order)) {
            return false;
        }

        $isCod = OrderPaymentMethod::isCashOnDelivery($order);
        if ($isCod) {
            return in_array($order->status, self::COD_CANCELLABLE_WITHOUT_EXTERNAL_SHIPMENT, true);
        }

        return in_array($order->status, self::ONLINE_CANCELLABLE, true);
    }

    /**
     * Cancel an unpaid / COD-pre-shipment order owned by the given user id.
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

            if ($this->hasExternalBostaShipment($locked)) {
                throw new DomainException(
                    'This order already has a live Bosta shipment. Contact support to cancel — '
                    .'automatic cancellation is blocked to avoid orphaning the delivery.'
                );
            }

            $isCod = OrderPaymentMethod::isCashOnDelivery($locked);
            $allowedStatuses = $isCod
                ? self::COD_CANCELLABLE_WITHOUT_EXTERNAL_SHIPMENT
                : self::ONLINE_CANCELLABLE;

            if (! in_array($locked->status, $allowedStatuses, true)) {
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

            if ($this->hasExternalBostaShipment($locked)) {
                throw new DomainException(
                    'A Bosta shipment was created while cancelling. Contact support to cancel the delivery.'
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

            Log::info('Customer cancelled order', [
                'order_id' => $locked->id,
                'order_number' => $locked->order_number,
                'user_id' => $userId,
                'is_cod' => $isCod,
                'has_reason' => $trimmedReason !== '',
            ]);

            return $locked->fresh(['items', 'payment', 'bostaShipment']) ?? $locked;
        });
    }

    private function hasExternalBostaShipment(Order $order): bool
    {
        if ($order->relationLoaded('bostaShipment')
            && $order->bostaShipment instanceof Shipment
            && filled($order->bostaShipment->external_shipment_id)
        ) {
            return true;
        }

        return Shipment::query()
            ->where('order_id', $order->id)
            ->where('provider', ShipmentProvider::Bosta->value)
            ->whereNotNull('external_shipment_id')
            ->where('external_shipment_id', '!=', '')
            ->exists();
    }
}
