<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;

/**
 * Deterministic client for automated tests and local dry runs.
 */
final class FakeBostaClient implements BostaClientInterface
{
    public function createShipment(Order $order): array
    {
        $id = 'fake-bosta-'.$order->id;

        return [
            'external_shipment_id' => $id,
            'tracking_number' => 'TRK-'.$order->order_number,
            'tracking_url' => 'https://bosta.example/track/'.$id,
            'provider_status' => 'Pickup requested',
            'raw' => [
                'driver' => 'fake',
                'order_id' => $order->id,
            ],
        ];
    }
}
