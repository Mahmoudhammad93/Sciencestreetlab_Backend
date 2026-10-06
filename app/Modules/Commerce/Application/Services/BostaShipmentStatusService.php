<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Application\Support\BostaWebhookResult;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Domain\Events\ShipmentDelivered;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\BostaStatusMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single authoritative Bosta → local shipment status processor.
 *
 * Used by webhook, API reconciliation, and Filament manual sync.
 * Delivered triggers the same OrderFulfillmentService path as webhooks.
 */
final class BostaShipmentStatusService
{
    public function __construct(
        private readonly BostaStatusMapper $statusMapper,
        private readonly OrderFulfillmentService $fulfillment,
    ) {}

    /**
     * @param  array<string, mixed>  $rawPayload  Sanitized provider payload (optional)
     */
    public function apply(
        Shipment $shipment,
        ?string $providerStatus,
        string $deliveryType = 'SEND',
        array $rawPayload = [],
        string $source = 'webhook',
    ): BostaWebhookResult {
        $mapped = $this->statusMapper->map($providerStatus, $deliveryType);
        $externalId = (string) ($shipment->external_shipment_id ?? '');

        return DB::transaction(function () use ($shipment, $providerStatus, $mapped, $rawPayload, $source, $externalId): BostaWebhookResult {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            $alreadyDelivered = $locked->status === ShipmentStatus::Delivered;
            $isTerminalFailure = $locked->status->isTerminalFailure();
            $previousStatus = $locked->status;

            $updates = [
                'provider_status' => $providerStatus,
                'metadata' => array_merge($locked->metadata ?? [], [
                    'last_status_source' => $source,
                    'last_status_synced_at' => now()->toIso8601String(),
                    'last_provider_payload' => $rawPayload !== [] ? $this->sanitizePayload($rawPayload) : ($locked->metadata['last_provider_payload'] ?? null),
                ]),
            ];

            if ($source === 'webhook') {
                $updates['last_webhook_at'] = now();
                $updates['metadata']['last_webhook_payload'] = $rawPayload !== []
                    ? $this->sanitizePayload($rawPayload)
                    : ($locked->metadata['last_webhook_payload'] ?? null);
            }

            $outcome = BostaWebhookResult::OUTCOME_PROCESSED;
            $shouldFulfill = false;

            if ($alreadyDelivered) {
                $outcome = BostaWebhookResult::OUTCOME_DUPLICATE;
            } elseif ($isTerminalFailure && $mapped !== $locked->status) {
                $outcome = BostaWebhookResult::OUTCOME_IGNORED_TERMINAL;
            } elseif ($mapped === ShipmentStatus::Unknown) {
                $outcome = BostaWebhookResult::OUTCOME_IGNORED_UNKNOWN_STATUS;
            } elseif ($this->wouldRegressProgress($previousStatus, $mapped)) {
                $outcome = BostaWebhookResult::OUTCOME_IGNORED_TERMINAL;
            } else {
                $updates['status'] = $mapped;

                if (in_array($mapped, [
                    ShipmentStatus::PickedUp,
                    ShipmentStatus::InTransit,
                    ShipmentStatus::OutForDelivery,
                ], true) && $locked->shipped_at === null) {
                    $updates['shipped_at'] = now();
                }

                if ($mapped === ShipmentStatus::Delivered) {
                    $updates['delivered_at'] = now();
                    $updates['status'] = ShipmentStatus::Delivered;
                    $shouldFulfill = true;
                }
            }

            $locked->update($updates);
            $locked = $locked->fresh(['order.items']) ?? $locked;

            $fulfilled = false;
            if ($shouldFulfill && $locked->status === ShipmentStatus::Delivered) {
                event(new ShipmentDelivered($locked));

                $order = $locked->order;
                if ($order !== null) {
                    $this->fulfillment->fulfillFromBostaDelivery($order);
                    $fulfilled = true;
                }

                $locked = $locked->fresh(['order']) ?? $locked;
            }

            Log::info('Bosta shipment status processed', [
                'source' => $source,
                'outcome' => $outcome,
                'duplicate' => $outcome === BostaWebhookResult::OUTCOME_DUPLICATE,
                'external_shipment_id' => $externalId !== '' ? $externalId : null,
                'local_shipment_id' => $locked->id,
                'local_order_id' => $locked->order_id,
                'provider_status' => $providerStatus,
                'previous_status' => $previousStatus->value,
                'normalized_status' => $locked->status->value,
                'mapped_status' => $mapped->value,
                'fulfilled' => $fulfilled,
            ]);

            return new BostaWebhookResult(
                outcome: $outcome,
                shipment: $locked,
                externalShipmentId: $externalId !== '' ? $externalId : null,
                providerStatus: $providerStatus,
                mappedStatus: $mapped,
                duplicate: $outcome === BostaWebhookResult::OUTCOME_DUPLICATE,
                fulfilled: $fulfilled,
            );
        });
    }

    private function wouldRegressProgress(ShipmentStatus $current, ShipmentStatus $incoming): bool
    {
        if ($current->isTerminalDelivered() || $current->isTerminalFailure()) {
            return $incoming !== $current;
        }

        $currentRank = $current->progressRank();
        $incomingRank = $incoming->progressRank();

        return $currentRank >= 0 && $incomingRank >= 0 && $incomingRank < $currentRank;
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
