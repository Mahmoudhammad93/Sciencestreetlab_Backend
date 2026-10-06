<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Listeners;

use App\Modules\Commerce\Application\Services\OrderDeliveredMailService;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * After fulfillment: attach matching user / mint claim + send Delivered/Course Ready email.
 *
 * EMAIL 2 — not the initial order confirmation (EMAIL 1 on OrderPaid / COD accept).
 */
final class EnsureGuestPurchaseClaimOnOrderFulfilled implements ShouldQueue
{
    /** Delivered mail must not run until OrderFulfilled transaction commits. */
    public bool $afterCommit = true;

    public function __construct(
        private readonly OrderDeliveredMailService $deliveredMail,
    ) {}

    public function handle(OrderFulfilled $event): void
    {
        $order = $event->order->fresh(['items.product', 'user', 'bostaShipment']) ?? $event->order;

        $this->deliveredMail->notifyIfNeeded($order);
    }
}
