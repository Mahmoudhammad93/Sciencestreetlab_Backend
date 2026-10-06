<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use App\Modules\Commerce\Application\Support\BostaWebhookResult;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety-net reconciliation: GET authoritative Bosta status for non-terminal shipments
 * and process through the same path as webhooks.
 */
final class BostaShipmentReconciliationService
{
    public function __construct(
        private readonly BostaWebhookService $webhooks,
    ) {}

    /**
     * @return array{
     *     checked: int,
     *     updated: int,
     *     fulfilled: int,
     *     unchanged: int,
     *     failed: int,
     *     skipped: int
     * }
     */
    public function reconcileEligible(?int $limit = null): array
    {
        $stats = [
            'checked' => 0,
            'updated' => 0,
            'fulfilled' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $lookbackDays = max(1, (int) config('bosta.reconcile_lookback_days', 30));
        $chunk = max(1, (int) config('bosta.reconcile_chunk_size', 50));
        $limit = $limit ?? (int) config('bosta.reconcile_max_per_run', 200);
        $limit = max(1, $limit);

        $query = Shipment::query()
            ->with('order:id,order_number')
            ->where('provider', ShipmentProvider::Bosta->value)
            ->whereNotNull('external_shipment_id')
            ->where('external_shipment_id', '!=', '')
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->whereNotIn('status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::Cancelled->value,
                ShipmentStatus::Failed->value,
            ])
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->orderBy('id');

        // Historical FakeBostaClient ids must never hit the live Bosta API.
        if (! (bool) config('bosta.use_fake')) {
            $query->where('external_shipment_id', 'not like', 'fake-bosta-%');
        }

        $processed = 0;

        $query->chunkById($chunk, function ($shipments) use (&$stats, &$processed, $limit): bool {
            foreach ($shipments as $shipment) {
                if ($processed >= $limit) {
                    return false;
                }

                $processed++;
                $stats['checked']++;

                $externalId = (string) ($shipment->external_shipment_id ?? '');
                $tracking = trim((string) ($shipment->tracking_number ?? ''));
                $skipFake = ! (bool) config('bosta.use_fake') && str_starts_with($externalId, 'fake-bosta-');
                if ($externalId === '' || $tracking === '' || $skipFake) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    $before = $shipment->status;
                    $result = $this->webhooks->syncFromBosta($shipment, 'reconciliation');
                    $after = $result->shipment?->status ?? $before;

                    if ($result->fulfilled) {
                        $stats['fulfilled']++;
                        $stats['updated']++;
                    } elseif ($after !== $before) {
                        $stats['updated']++;
                    } elseif ($result->outcome === BostaWebhookResult::OUTCOME_DUPLICATE
                        || $result->outcome === BostaWebhookResult::OUTCOME_IGNORED_TERMINAL
                        || $result->outcome === BostaWebhookResult::OUTCOME_IGNORED_UNKNOWN_STATUS
                    ) {
                        $stats['unchanged']++;
                    } else {
                        $stats['unchanged']++;
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    Log::warning('Bosta shipment reconciliation failed', [
                        'source' => 'reconciliation',
                        'local_shipment_id' => $shipment->id,
                        'local_order_id' => $shipment->order_id,
                        'external_shipment_id' => $shipment->external_shipment_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Soft rate-limit against Bosta search API.
                usleep(150000);
            }

            return true;
        });

        Log::info('Bosta shipment reconciliation finished', $stats);

        return $stats;
    }

    public function reconcileShipment(Shipment $shipment, string $source = 'manual'): BostaWebhookResult
    {
        return $this->webhooks->syncFromBosta($shipment, $source);
    }
}
