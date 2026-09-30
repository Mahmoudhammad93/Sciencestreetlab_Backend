<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Application\Support\BostaWebhookResult;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Domain\Events\ShipmentDelivered;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\BostaStatusMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Idempotent Bosta webhook processor.
 *
 * Resolves shipments by stored Bosta external_shipment_id only.
 * Never invents orders/shipments. Never treats Unknown as Delivered.
 * Never downgrades Delivered (or other terminal statuses) via stale events.
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
    public function handle(array $payload): BostaWebhookResult
    {
        $externalId = $this->extractExternalId($payload);
        if ($externalId === null || $externalId === '') {
            throw new InvalidArgumentException('Bosta webhook missing shipment identifier.');
        }

        $providerStatus = $this->extractProviderStatus($payload);
        $mapped = $this->statusMapper->map($providerStatus);

        return DB::transaction(function () use ($externalId, $providerStatus, $mapped, $payload): BostaWebhookResult {
            /** @var Shipment|null $shipment */
            $shipment = Shipment::query()
                ->where('provider', ShipmentProvider::Bosta->value)
                ->where('external_shipment_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                Log::warning('Bosta webhook for unknown shipment ignored', [
                    'external_shipment_id' => $externalId,
                    'provider_status' => $providerStatus,
                    'mapped_status' => $mapped->value,
                    'outcome' => BostaWebhookResult::OUTCOME_IGNORED_UNKNOWN_SHIPMENT,
                ]);

                return new BostaWebhookResult(
                    outcome: BostaWebhookResult::OUTCOME_IGNORED_UNKNOWN_SHIPMENT,
                    externalShipmentId: $externalId,
                    providerStatus: $providerStatus,
                    mappedStatus: $mapped,
                );
            }

            $alreadyDelivered = $shipment->status === ShipmentStatus::Delivered;
            $isTerminalFailure = $shipment->status->isTerminalFailure();
            $previousStatus = $shipment->status;

            $updates = [
                'provider_status' => $providerStatus,
                'last_webhook_at' => now(),
                'metadata' => array_merge($shipment->metadata ?? [], [
                    'last_webhook_payload' => $this->sanitizePayload($payload),
                ]),
            ];

            $outcome = BostaWebhookResult::OUTCOME_PROCESSED;
            $shouldFulfill = false;

            if ($alreadyDelivered) {
                // Duplicate / stale after delivered: refresh webhook metadata only.
                $outcome = BostaWebhookResult::OUTCOME_DUPLICATE;
            } elseif ($isTerminalFailure && $mapped !== $shipment->status) {
                // Cancelled/Failed: do not regress to mid-journey or jump to delivered via stale events.
                $outcome = BostaWebhookResult::OUTCOME_IGNORED_TERMINAL;
            } elseif ($mapped === ShipmentStatus::Unknown) {
                $outcome = BostaWebhookResult::OUTCOME_IGNORED_UNKNOWN_STATUS;
            } else {
                $updates['status'] = $mapped;

                if (in_array($mapped, [
                    ShipmentStatus::PickedUp,
                    ShipmentStatus::InTransit,
                    ShipmentStatus::OutForDelivery,
                ], true) && $shipment->shipped_at === null) {
                    $updates['shipped_at'] = now();
                }

                if ($mapped === ShipmentStatus::Delivered) {
                    $updates['delivered_at'] = now();
                    $updates['status'] = ShipmentStatus::Delivered;
                    $shouldFulfill = true;
                }
            }

            $shipment->update($updates);
            $shipment = $shipment->fresh(['order.items']) ?? $shipment;

            $fulfilled = false;
            if ($shouldFulfill && $shipment->status === ShipmentStatus::Delivered) {
                event(new ShipmentDelivered($shipment));

                $order = $shipment->order;
                if ($order !== null) {
                    $this->fulfillment->fulfillFromBostaDelivery($order);
                    $fulfilled = true;
                }

                $shipment = $shipment->fresh(['order']) ?? $shipment;
            }

            Log::info('Bosta webhook processed', [
                'outcome' => $outcome,
                'duplicate' => $outcome === BostaWebhookResult::OUTCOME_DUPLICATE,
                'external_shipment_id' => $externalId,
                'local_shipment_id' => $shipment->id,
                'local_order_id' => $shipment->order_id,
                'provider_status' => $providerStatus,
                'previous_status' => $previousStatus->value,
                'normalized_status' => $shipment->status->value,
                'mapped_status' => $mapped->value,
                'fulfilled' => $fulfilled,
            ]);

            return new BostaWebhookResult(
                outcome: $outcome,
                shipment: $shipment,
                externalShipmentId: $externalId,
                providerStatus: $providerStatus,
                mappedStatus: $mapped,
                duplicate: $outcome === BostaWebhookResult::OUTCOME_DUPLICATE,
                fulfilled: $fulfilled,
            );
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
        unset(
            $payload['signature'],
            $payload['secret'],
            $payload['apiKey'],
            $payload['api_key'],
            $payload['authorization'],
            $payload['Authorization'],
        );

        return $payload;
    }
}
