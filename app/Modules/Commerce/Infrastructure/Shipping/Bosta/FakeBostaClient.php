<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;

/**
 * Deterministic client for automated tests and local/staging dry runs.
 *
 * Must never run when APP_ENV=production (enforced by CommerceServiceProvider
 * and a secondary guard here).
 */
final class FakeBostaClient implements BostaClientInterface
{
    public function createShipment(Order $order): array
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('FakeBostaClient must not create shipments in production.');
        }

        $id = 'fake-bosta-'.$order->id;

        return [
            'external_shipment_id' => $id,
            'tracking_number' => 'TRK-'.$order->order_number,
            'tracking_url' => 'https://bosta.example/track/'.$id,
            'provider_status' => '10',
            'raw' => [
                'driver' => 'fake',
                'test_mode' => true,
                'order_id' => $order->id,
                'type' => 10,
                'cod' => 0,
                'businessReference' => $order->order_number,
            ],
            'request' => [
                'type' => 10,
                'cod' => 0,
                'businessReference' => $order->order_number,
            ],
        ];
    }
}
