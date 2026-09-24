<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Central place to mark an order paid/fulfilled so online payment and admin
 * manual fulfillment share the same OrderPaid → enrollment behavior.
 */
final class OrderFulfillmentService
{
    /**
     * Statuses that mean the order has been fulfilled enough to grant access.
     *
     * @var list<string>
     */
    private const FULFILLMENT_STATUSES = [
        OrderStatus::Paid->value,
        OrderStatus::Processing->value,
        OrderStatus::Shipped->value,
        OrderStatus::Delivered->value,
    ];

    /**
     * Mark the order paid once and dispatch OrderPaid exactly once.
     * Idempotent when paid_at is already set.
     */
    public function markPaid(Order $order): Order
    {
        if ($order->paid_at !== null) {
            if ($order->status !== OrderStatus::Paid->value
                && ! in_array($order->status, [
                    OrderStatus::Processing->value,
                    OrderStatus::Shipped->value,
                    OrderStatus::Delivered->value,
                ], true)
            ) {
                $order->update(['status' => OrderStatus::Paid->value]);
            }

            return $order->fresh(['items']) ?? $order;
        }

        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->paid_at !== null) {
                return $locked->loadMissing('items');
            }

            $locked->update([
                'status' => OrderStatus::Paid->value,
                'paid_at' => now(),
            ]);

            $fresh = $locked->fresh(['items']);
            event(new OrderPaid($fresh));

            return $fresh;
        });
    }

    /**
     * Apply an admin status/notes update. When moving into a fulfillment
     * status (paid/shipped/…), ensure payment fulfillment runs first.
     *
     * @param  array{status?: string, notes?: string|null}  $data
     */
    public function applyAdminUpdate(Order $order, array $data): Order
    {
        $newStatus = isset($data['status']) ? (string) $data['status'] : $order->status;
        $notes = array_key_exists('notes', $data) ? $data['notes'] : $order->notes;

        if ($this->requiresFulfillment($order, $newStatus)) {
            $order = $this->markPaid($order);
        }

        $updates = [];
        if ($notes !== $order->notes) {
            $updates['notes'] = $notes;
        }

        // Preserve paid_at; only change status after fulfillment when needed.
        if ($newStatus !== $order->status) {
            $updates['status'] = $newStatus;
        }

        if ($updates !== []) {
            $order->update($updates);
        }

        return $order->fresh(['items']) ?? $order;
    }

    private function requiresFulfillment(Order $order, string $newStatus): bool
    {
        if ($order->paid_at !== null) {
            return false;
        }

        return in_array($newStatus, self::FULFILLMENT_STATUSES, true);
    }
}
