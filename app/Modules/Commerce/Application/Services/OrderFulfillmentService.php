<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Central place for payment accounting (OrderPaid) and course-access
 * fulfillment (OrderFulfilled). Online payment and admin COD share OrderPaid;
 * Bosta-controlled Egypt kits defer OrderFulfilled until DELIVERED.
 */
final class OrderFulfillmentService
{
    /**
     * Statuses that mean non-Bosta admin fulfillment should grant access.
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
     * For non-delivery-gated orders, also dispatches OrderFulfilled once.
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

            $fresh = $order->fresh(['items']) ?? $order;

            if (! $this->defersFulfillmentUntilDelivery($fresh)) {
                return $this->fulfill($fresh);
            }

            return $fresh;
        }

        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->paid_at !== null) {
                $loaded = $locked->loadMissing('items');
                if (! $this->defersFulfillmentUntilDelivery($loaded)) {
                    return $this->fulfill($loaded);
                }

                return $loaded;
            }

            $locked->update([
                'status' => OrderStatus::Paid->value,
                'paid_at' => now(),
            ]);

            $fresh = $locked->fresh(['items']);
            event(new OrderPaid($fresh));

            if (! $this->defersFulfillmentUntilDelivery($fresh)) {
                return $this->fulfill($fresh);
            }

            return $fresh;
        });
    }

    /**
     * Grant course access / confirmation email. Idempotent via fulfilled_at.
     */
    public function fulfill(Order $order): Order
    {
        if ($order->fulfilled_at !== null) {
            return $order->fresh(['items']) ?? $order;
        }

        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->fulfilled_at !== null) {
                return $locked->loadMissing('items');
            }

            $locked->update(['fulfilled_at' => now()]);

            $fresh = $locked->fresh(['items']);
            event(new OrderFulfilled($fresh));

            return $fresh;
        });
    }

    /**
     * Authoritative Bosta DELIVERED path: mark paid (COD), set delivered, fulfill.
     */
    public function fulfillFromBostaDelivery(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->paid_at === null) {
                $locked = $this->markPaid($locked);
            }

            $updates = [];
            if ($locked->status !== OrderStatus::Delivered->value) {
                $updates['status'] = OrderStatus::Delivered->value;
            }
            if ($locked->delivered_at === null) {
                $updates['delivered_at'] = now();
            }
            if ($updates !== []) {
                $locked->update($updates);
            }

            return $this->fulfill($locked->fresh(['items']) ?? $locked);
        });
    }

    /**
     * Apply an admin status/notes update.
     *
     * For Bosta-gated Egypt orders: admin status changes (including Shipped)
     * MUST NOT unlock the course — only Bosta DELIVERED does.
     *
     * @param  array{status?: string, notes?: string|null}  $data
     */
    public function applyAdminUpdate(Order $order, array $data): Order
    {
        $newStatus = isset($data['status']) ? (string) $data['status'] : $order->status;
        $notes = array_key_exists('notes', $data) ? $data['notes'] : $order->notes;

        if ($this->defersFulfillmentUntilDelivery($order)) {
            $updates = [];
            if ($notes !== $order->notes) {
                $updates['notes'] = $notes;
            }
            if ($newStatus !== $order->status) {
                $updates['status'] = $newStatus;
            }
            if ($newStatus === OrderStatus::Shipped->value && $order->shipped_at === null) {
                $updates['shipped_at'] = now();
            }
            if ($updates !== []) {
                $order->update($updates);
            }

            return $order->fresh(['items']) ?? $order;
        }

        if ($this->requiresFulfillment($order, $newStatus)) {
            $order = $this->markPaid($order);
        }

        $updates = [];
        if ($notes !== $order->notes) {
            $updates['notes'] = $notes;
        }

        if ($newStatus !== $order->status) {
            $updates['status'] = $newStatus;
        }

        if ($newStatus === OrderStatus::Shipped->value && $order->shipped_at === null) {
            $updates['shipped_at'] = now();
        }
        if ($newStatus === OrderStatus::Delivered->value && $order->delivered_at === null) {
            $updates['delivered_at'] = now();
        }

        if ($updates !== []) {
            $order->update($updates);
        }

        return $order->fresh(['items']) ?? $order;
    }

    public function defersFulfillmentUntilDelivery(Order $order): bool
    {
        return (bool) $order->requires_delivery_fulfillment;
    }

    private function requiresFulfillment(Order $order, string $newStatus): bool
    {
        if ($order->paid_at !== null && $order->fulfilled_at !== null) {
            return false;
        }

        if ($order->paid_at !== null) {
            return false;
        }

        return in_array($newStatus, self::FULFILLMENT_STATUSES, true);
    }
}
