<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Listeners;

use App\Modules\Commerce\Application\Services\GuestPurchaseClaimService;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Creates/sends guest course claim after eligible fulfillment timing.
 */
final class EnsureGuestPurchaseClaimOnOrderFulfilled implements ShouldQueue
{
    public function __construct(
        private readonly GuestPurchaseClaimService $claims,
    ) {}

    public function handle(OrderFulfilled $event): void
    {
        $order = $event->order->fresh(['items.product']) ?? $event->order;

        if (! $order->is_guest) {
            return;
        }

        $this->claims->ensureAndNotify($order);
    }
}
