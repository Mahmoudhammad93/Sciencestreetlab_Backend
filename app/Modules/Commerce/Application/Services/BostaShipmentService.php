<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Idempotent Bosta shipment creation for physical (kit) Egypt orders.
 */
final class BostaShipmentService
{
    public function __construct(
        private readonly BostaClientInterface $client,
    ) {}

    public function shouldCreateForOrder(Order $order): bool
    {
        if (! (bool) config('bosta.enabled')) {
            return false;
        }

        $order->loadMissing(['items.product']);

        foreach ($order->items as $item) {
            $type = $item->metadata['product_type'] ?? $item->product?->type?->value;
            if ($type === ProductType::Kit->value || $type === ProductType::Bundle->value) {
                return true;
            }
            if ($item->product?->type === ProductType::Kit || $item->product?->type === ProductType::Bundle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure exactly one Bosta shipment exists for the order.
     * Safe to call repeatedly.
     */
    public function ensureShipmentForOrder(Order $order): ?Shipment
    {
        if (! $this->shouldCreateForOrder($order)) {
            return null;
        }

        return DB::transaction(function () use ($order): Shipment {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $existing = Shipment::query()
                ->where('order_id', $locked->id)
                ->where('provider', ShipmentProvider::Bosta->value)
                ->first();

            if ($existing !== null) {
                if (! $locked->requires_delivery_fulfillment) {
                    $locked->update(['requires_delivery_fulfillment' => true]);
                }

                return $existing;
            }

            $metadata = ['creation_attempted_at' => now()->toIso8601String()];

            try {
                $result = $this->client->createShipment($locked);
                $shipment = Shipment::query()->create([
                    'order_id' => $locked->id,
                    'provider' => ShipmentProvider::Bosta->value,
                    'external_shipment_id' => $result['external_shipment_id'],
                    'tracking_number' => $result['tracking_number'] ?? null,
                    'tracking_url' => $result['tracking_url'] ?? null,
                    'status' => ShipmentStatus::Created,
                    'provider_status' => $result['provider_status'] ?? null,
                    'metadata' => array_merge($metadata, ['raw' => $result['raw'] ?? []]),
                ]);
            } catch (Throwable $e) {
                Log::warning('Bosta shipment creation deferred', [
                    'order_id' => $locked->id,
                    'reason' => $e->getMessage(),
                ]);

                $shipment = Shipment::query()->create([
                    'order_id' => $locked->id,
                    'provider' => ShipmentProvider::Bosta->value,
                    'external_shipment_id' => null,
                    'status' => ShipmentStatus::Pending,
                    'metadata' => array_merge($metadata, [
                        'blocked' => 'BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS',
                        'error' => $e->getMessage(),
                    ]),
                ]);
            }

            $locked->update(['requires_delivery_fulfillment' => true]);

            return $shipment;
        });
    }

    /**
     * Attach a pre-built Bosta shipment (tests / ops) without calling the API.
     *
     * @param  array{external_shipment_id: string, tracking_number?: ?string, tracking_url?: ?string, provider_status?: ?string}  $data
     */
    public function attachExistingShipment(Order $order, array $data): Shipment
    {
        if (empty($data['external_shipment_id'])) {
            throw new RuntimeException('external_shipment_id is required.');
        }

        return DB::transaction(function () use ($order, $data): Shipment {
            $existing = Shipment::query()
                ->where('order_id', $order->id)
                ->where('provider', ShipmentProvider::Bosta->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $shipment = Shipment::query()->create([
                'order_id' => $order->id,
                'provider' => ShipmentProvider::Bosta->value,
                'external_shipment_id' => $data['external_shipment_id'],
                'tracking_number' => $data['tracking_number'] ?? null,
                'tracking_url' => $data['tracking_url'] ?? null,
                'status' => ShipmentStatus::Created,
                'provider_status' => $data['provider_status'] ?? null,
                'metadata' => ['source' => 'attach'],
            ]);

            $order->update(['requires_delivery_fulfillment' => true]);

            return $shipment;
        });
    }
}
