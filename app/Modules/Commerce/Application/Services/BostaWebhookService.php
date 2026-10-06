<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Application\Support\BostaWebhookResult;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\BostaStatusMapper;
use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Resolves Bosta webhook payloads and delegates to the central status processor.
 */
final class BostaWebhookService
{
    public function __construct(
        private readonly BostaStatusMapper $statusMapper,
        private readonly BostaShipmentStatusService $statusProcessor,
        private readonly BostaClientInterface $bostaClient,
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
        $deliveryType = $this->extractDeliveryType($payload);

        /** @var Shipment|null $shipment */
        $shipment = Shipment::query()
            ->where('provider', ShipmentProvider::Bosta->value)
            ->where('external_shipment_id', $externalId)
            ->first();

        if ($shipment === null) {
            $tracking = $this->extractTrackingNumber($payload);
            if ($tracking !== null && $tracking !== '') {
                $shipment = Shipment::query()
                    ->where('provider', ShipmentProvider::Bosta->value)
                    ->where('tracking_number', $tracking)
                    ->first();
            }
        }

        if ($shipment === null) {
            $mapped = $this->statusMapper->map($providerStatus, $deliveryType);
            Log::warning('Bosta webhook for unknown shipment ignored', [
                'source' => 'webhook',
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

        return $this->statusProcessor->apply(
            shipment: $shipment,
            providerStatus: $providerStatus,
            deliveryType: $deliveryType,
            rawPayload: $payload,
            source: 'webhook',
        );
    }

    /**
     * Authoritative GET from Bosta + central status processor.
     */
    public function syncFromBosta(Shipment $shipment, string $source = 'reconciliation'): BostaWebhookResult
    {
        if ($shipment->provider !== ShipmentProvider::Bosta) {
            throw new InvalidArgumentException('Shipment is not a Bosta shipment.');
        }

        $externalId = trim((string) ($shipment->external_shipment_id ?? ''));
        if ($externalId === '') {
            throw new InvalidArgumentException('Shipment has no Bosta external id.');
        }

        $shipment->loadMissing('order');
        $tracking = trim((string) ($shipment->tracking_number ?? ''));
        $businessReference = trim((string) ($shipment->order?->order_number ?? ''));

        try {
            $delivery = $this->bostaClient->getDelivery(
                $externalId,
                $tracking !== '' ? $tracking : null,
                $businessReference !== '' ? $businessReference : null,
            );
        } catch (Throwable $e) {
            Log::warning('Bosta delivery status fetch failed', [
                'source' => $source,
                'local_shipment_id' => $shipment->id,
                'local_order_id' => $shipment->order_id,
                'external_shipment_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unable to fetch Bosta delivery status: '.$e->getMessage(), 0, $e);
        }

        $providerStatus = isset($delivery['provider_status']) ? (string) $delivery['provider_status'] : null;
        $deliveryType = isset($delivery['type']) ? (string) $delivery['type'] : 'SEND';
        $raw = is_array($delivery['raw'] ?? null) ? $delivery['raw'] : $delivery;

        // Keep local tracking in sync when Bosta returns a newer number.
        $tracking = $delivery['tracking_number'] ?? null;
        if (is_string($tracking) && $tracking !== '' && $shipment->tracking_number !== $tracking) {
            $shipment->update(['tracking_number' => $tracking]);
            $shipment = $shipment->fresh() ?? $shipment;
        }

        return $this->statusProcessor->apply(
            shipment: $shipment,
            providerStatus: $providerStatus,
            deliveryType: $deliveryType,
            rawPayload: $raw,
            source: $source,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractExternalId(array $payload): ?string
    {
        foreach (['_id', 'external_shipment_id', 'shipmentId', 'shipment_id', 'id'] as $key) {
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
        foreach (['state', 'provider_status', 'status', 'deliveryState', 'delivery_state'] as $key) {
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
     */
    private function extractDeliveryType(array $payload): string
    {
        foreach (['type', 'deliveryType', 'delivery_type'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return strtoupper((string) $payload[$key]);
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->extractDeliveryType($payload['data']);
        }

        return 'SEND';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractTrackingNumber(array $payload): ?string
    {
        foreach (['trackingNumber', 'tracking_number', 'trackingNo', 'tracking_no'] as $key) {
            if (! empty($payload[$key]) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->extractTrackingNumber($payload['data']);
        }

        return null;
    }
}
