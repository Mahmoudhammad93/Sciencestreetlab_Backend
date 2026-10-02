<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Listeners;

use App\Modules\Commerce\Application\Services\BostaShipmentService;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After verified payment (OrderPaid), create at most one Bosta delivery
 * for physical kit/bundle orders. Never runs for unpaid orders.
 */
final class CreateBostaShipmentOnOrderPaid
{
    public function __construct(
        private readonly BostaShipmentService $shipments,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->fresh(['items.product', 'bostaShipment']) ?? $event->order;

        try {
            $this->shipments->ensureShipmentForOrder($order);
        } catch (Throwable $e) {
            // Payment must remain paid; failure is stored on shipment as creation_failed.
            Log::error('Post-payment Bosta shipment ensure failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
