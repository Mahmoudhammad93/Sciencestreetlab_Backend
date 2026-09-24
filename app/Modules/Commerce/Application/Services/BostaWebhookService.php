<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Domain\Events\ShipmentDelivered;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\BostaStatusMapper;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Idempotent Bosta webhook processor.
 */
final class BostaWebhookService
{
    public function __construct(
        private readonly BostaStatusMapper $statusMapper,
        private readonly OrderFulfillmentService $fulfillment,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): Shipment
    {
        $externalId = $this->extractExternalId($payload);
        if ($externalId === null || $externalId === '') {
            throw new InvalidArgumentException('Bosta webhook missing shipment identifier.');
        }

        $providerStatus = $this->extractProviderStatus($payload);
        $mapped = $this->statusMapper->map($providerStatus);

        return DB::transaction(function () use ($externalId, $providerStatus, $mapped, $payload): Shipment {
            /** @var Shipment|null $shipment */
            $shipment = Shipment::query()
                ->where('provider', ShipmentProvider::Bosta->value)
                ->where('external_shipment_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                throw new InvalidArgumentException('Unknown Bosta shipment: '.$externalId);
            }

            $alreadyDelivered = $shipment->status === ShipmentStatus::Delivered;

            $updates = [
                'provider_status' => $providerStatus,
                'last_webhook_at' => now(),
                'metadata' => array_merge($shipment->metadata ?? [], [
                    'last_webhook_payload' => $this->sanitizePayload($payload),
                ]),
            ];

            // Never downgrade from Delivered.
            if (! $alreadyDelivered && $mapped !== ShipmentStatus::Unknown) {
                $updates['status'] = $mapped;
            }

            if (! $alreadyDelivered && in_array($mapped, [
                ShipmentStatus::PickedUp,
                ShipmentStatus::InTransit,
                ShipmentStatus::OutForDelivery,
            ], true) && $shipment->shipped_at === null) {
                $updates['shipped_at'] = now();
            }

            if (! $alreadyDelivered && $mapped === ShipmentStatus::Delivered) {
                $updates['delivered_at'] = now();
                $updates['status'] = ShipmentStatus::Delivered;
            }

            $shipment->update($updates);
            $shipment = $shipment->fresh(['order.items']) ?? $shipment;

            if (! $alreadyDelivered && $shipment->status === ShipmentStatus::Delivered) {
                event(new ShipmentDelivered($shipment));

                $order = $shipment->order;
                if ($order !== null) {
                    $this->fulfillment->fulfillFromBostaDelivery($order);
                }
            }

            return $shipment->fresh(['order']) ?? $shipment;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractExternalId(array $payload): ?string
    {
        foreach (['external_shipment_id', 'shipmentId', 'shipment_id', 'trackingNumber', 'tracking_number', '_id', 'id'] as $key) {
            if (! empty($payload[$key]) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->extractExternalId($payload['data']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractProviderStatus(array $payload): ?string
    {
        foreach (['provider_status', 'state', 'status', 'deliveryState', 'delivery_state'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->extractProviderStatus($payload['data']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        unset($payload['signature'], $payload['secret'], $payload['apiKey'], $payload['api_key']);

        return $payload;
    }
}
