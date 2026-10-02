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
 * Idempotent Bosta shipment creation for physical (kit/bundle) Egypt orders.
 *
 * External create runs ONLY after verified payment (paid_at set).
 * Checkout may mark requires_delivery_fulfillment without calling Bosta.
 */
final class BostaShipmentService
{
    public function __construct(
        private readonly BostaClientInterface $client,
    ) {}

    public function orderRequiresDelivery(Order $order): bool
    {
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
     * Checkout-time flag only — does NOT call the Bosta API.
     */
    public function markRequiresDeliveryIfNeeded(Order $order): Order
    {
        if (! (bool) config('bosta.enabled')) {
            return $order;
        }

        if (! $this->orderRequiresDelivery($order)) {
            return $order;
        }

        if (! $order->requires_delivery_fulfillment) {
            $order->update(['requires_delivery_fulfillment' => true]);
        }

        return $order->fresh(['items', 'bostaShipment']) ?? $order;
    }

    public function shouldCreateForOrder(Order $order): bool
    {
        if (! (bool) config('bosta.enabled')) {
            Log::info('Bosta shipment skipped: BOSTA_ENABLED=false', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        if ($order->paid_at === null) {
            Log::info('Bosta shipment skipped: order unpaid', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        if ($order->delivered_at !== null) {
            Log::info('Bosta shipment skipped: order already delivered', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        if (! $this->orderRequiresDelivery($order)) {
            Log::info('Bosta shipment skipped: no kit/bundle items on order', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Ensure exactly one Bosta shipment exists for the paid order.
     * Safe to call repeatedly. Retries the SAME local row when create previously failed.
     */
    public function ensureShipmentForOrder(Order $order): ?Shipment
    {
        if (! $this->shouldCreateForOrder($order)) {
            return null;
        }

        return DB::transaction(function () use ($order): Shipment {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->paid_at === null) {
                throw new RuntimeException('Refusing Bosta create for unpaid order '.$locked->id);
            }

            $existing = Shipment::query()
                ->where('order_id', $locked->id)
                ->where('provider', ShipmentProvider::Bosta->value)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && filled($existing->external_shipment_id)) {
                if (! $locked->requires_delivery_fulfillment) {
                    $locked->update(['requires_delivery_fulfillment' => true]);
                }

                return $existing;
            }

            if (! $locked->requires_delivery_fulfillment) {
                $locked->update(['requires_delivery_fulfillment' => true]);
            }

            $shipment = $existing ?? Shipment::query()->create([
                'order_id' => $locked->id,
                'provider' => ShipmentProvider::Bosta->value,
                'external_shipment_id' => null,
                'status' => ShipmentStatus::Pending,
                'metadata' => [
                    'creation_state' => 'pending_creation',
                    'creation_attempted_at' => now()->toIso8601String(),
                ],
            ]);

            // Re-check after create race: another worker may have set external id.
            $shipment->refresh();
            if (filled($shipment->external_shipment_id)) {
                return $shipment;
            }

            try {
                $result = $this->client->createShipment($locked);

                if (! filled($result['external_shipment_id'] ?? null)) {
                    throw new RuntimeException('Bosta create returned empty external_shipment_id.');
                }

                $shipment->update([
                    'external_shipment_id' => $result['external_shipment_id'],
                    'tracking_number' => $result['tracking_number'] ?? null,
                    'tracking_url' => $result['tracking_url'] ?? null,
                    'status' => ShipmentStatus::Created,
                    'provider_status' => $result['provider_status'] ?? null,
                    'metadata' => array_merge($shipment->metadata ?? [], [
                        'creation_state' => 'created',
                        'created_at_provider' => now()->toIso8601String(),
                        'raw' => $result['raw'] ?? [],
                        'request' => $result['request'] ?? null,
                        'business_reference' => $locked->order_number,
                    ]),
                ]);
            } catch (Throwable $e) {
                Log::warning('Bosta shipment creation failed (retryable)', [
                    'order_id' => $locked->id,
                    'shipment_id' => $shipment->id,
                    'business_reference' => $locked->order_number,
                    'reason' => $e->getMessage(),
                ]);

                $shipment->update([
                    'status' => ShipmentStatus::Failed,
                    'metadata' => array_merge($shipment->metadata ?? [], [
                        'creation_state' => 'creation_failed',
                        'last_error' => $e->getMessage(),
                        'last_failed_at' => now()->toIso8601String(),
                        'business_reference' => $locked->order_number,
                    ]),
                ]);
            }

            return $shipment->fresh() ?? $shipment;
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
                'metadata' => ['source' => 'attach', 'creation_state' => 'created'],
            ]);

            $order->update(['requires_delivery_fulfillment' => true]);

            return $shipment;
        });
    }
}
