<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Application\Support\OrderPaymentMethod;
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
        $cod = $this->collectibleCodAmount($order);

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
                'cod' => $cod,
                'businessReference' => $order->order_number,
            ],
            'request' => [
                'type' => 10,
                'cod' => $cod,
                'businessReference' => $order->order_number,
            ],
        ];
    }

    /**
     * @var array<string, array{state: int|string, type?: string, tracking_number?: string|null}>
     */
    private static array $deliveryStates = [];

    /**
     * Test helper: seed authoritative Bosta state for getDelivery().
     */
    public static function setDeliveryState(
        string $externalShipmentId,
        int|string $state,
        string $type = 'SEND',
        ?string $trackingNumber = null,
    ): void {
        self::$deliveryStates[$externalShipmentId] = [
            'state' => $state,
            'type' => $type,
            'tracking_number' => $trackingNumber,
        ];
    }

    public static function clearDeliveryStates(): void
    {
        self::$deliveryStates = [];
    }

    public function getDelivery(
        string $externalShipmentId,
        ?string $trackingNumber = null,
        ?string $businessReference = null,
    ): array {
        if (app()->environment('production')) {
            throw new \RuntimeException('FakeBostaClient must not fetch deliveries in production.');
        }

        $externalShipmentId = trim($externalShipmentId);
        $seeded = self::$deliveryStates[$externalShipmentId] ?? null;
        $state = $seeded['state'] ?? 10;
        $type = strtoupper((string) ($seeded['type'] ?? 'SEND'));
        $tracking = $seeded['tracking_number'] ?? $trackingNumber ?? ('TRK-'.$externalShipmentId);

        return [
            'external_shipment_id' => $externalShipmentId,
            'tracking_number' => $tracking,
            'provider_status' => (string) $state,
            'type' => $type,
            'raw' => [
                '_id' => $externalShipmentId,
                'trackingNumber' => $tracking,
                'businessReference' => $businessReference,
                'state' => ['code' => $state, 'value' => (string) $state],
                'type' => $type,
                'driver' => 'fake',
            ],
        ];
    }

    private function collectibleCodAmount(Order $order): float|int
    {
        if (! OrderPaymentMethod::isCashOnDelivery($order)) {
            return 0;
        }

        $amount = round((float) $order->total, 2);
        if (fmod($amount, 1.0) === 0.0) {
            return (int) $amount;
        }

        return $amount;
    }
}
