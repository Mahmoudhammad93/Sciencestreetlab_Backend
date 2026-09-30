<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Repair historical order timestamps written before Checkpoint 6A UTC-session fix.
 *
 * Scope: migration-created order maps for a running WordPress migration run only.
 * Contract (unchanged from importer):
 * - date_created_gmt → paid_at (UTC; best-available activity time, not gateway paid)
 * - wc-completed → fulfilled_at from date_updated_gmt (UTC); else fulfilled_at = null
 *
 * Does not create/delete orders or maps, does not change ownership/financials/user_id,
 * does not dispatch OrderPaid/OrderFulfilled, and does not touch native orders.
 */
final class WordPressOrderTimestampRepairService
{
    private bool $utcSessionEnsured = false;

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly MigrationRunService $runs,
        private readonly WordPressOrderImporter $importer,
    ) {}

    /**
     * @param  iterable<int, object>|null  $sourceOverride  Test-only source rows keyed by id.
     * @return array<string, mixed>
     */
    public function repair(int $migrationRunId, bool $dryRun = true, ?iterable $sourceOverride = null): array
    {
        $run = $this->requireRunningWordPressRun($migrationRunId);
        $this->ensureUtcSessionTimezone();

        $maps = LegacyImportMap::query()
            ->where('migration_run_id', $run->id)
            ->where('source', LegacyImportMapRepository::SOURCE_WORDPRESS)
            ->where('entity_type', 'order')
            ->orderBy('id')
            ->get()
            ->filter(fn (LegacyImportMap $map): bool => $map->wasCreatedByMigration() && ! $map->wasMappedToExisting())
            ->values();

        $sources = $sourceOverride !== null
            ? $this->indexSourceOverride($sourceOverride)
            : $this->loadSourceOrders();

        $fieldStats = [
            'paid_at' => ['source_field' => 'date_created_gmt', 'matched' => 0, 'mismatched' => 0, 'null_source' => 0, 'null_target' => 0, 'would_update' => 0, 'updated' => 0],
            'fulfilled_at' => ['source_field' => 'date_updated_gmt|null', 'matched' => 0, 'mismatched' => 0, 'null_source' => 0, 'null_target' => 0, 'would_update' => 0, 'updated' => 0],
            'created_at' => ['source_field' => 'n/a (import wall-clock)', 'matched' => 0, 'mismatched' => 0, 'null_source' => 0, 'null_target' => 0, 'would_update' => 0, 'updated' => 0],
            'updated_at' => ['source_field' => 'n/a (import wall-clock)', 'matched' => 0, 'mismatched' => 0, 'null_source' => 0, 'null_target' => 0, 'would_update' => 0, 'updated' => 0],
        ];

        $scanned = 0;
        $alreadyCorrect = 0;
        $wouldUpdateOrders = 0;
        $updatedOrders = 0;
        $failed = 0;
        $repairTargetLegacyIds = [];
        $samples = [];
        $missingSource = 0;
        $missingLocal = 0;
        $notesPaidAtSourceWouldUpdate = 0;
        $notesPaidAtSourceUpdated = 0;
        $mapMetadataWouldUpdate = 0;
        $mapMetadataUpdated = 0;

        foreach ($maps as $map) {
            $scanned++;
            $legacyId = (string) $map->legacy_id;
            $src = $sources->get($legacyId) ?? $sources->get((int) $legacyId);
            if ($src === null) {
                $missingSource++;
                $failed++;

                continue;
            }

            $order = Order::query()->find($map->local_id);
            if ($order === null) {
                $missingLocal++;
                $failed++;

                continue;
            }

            $plan = $this->planRepair($order, $src, $map);
            foreach (['paid_at', 'fulfilled_at', 'created_at', 'updated_at'] as $field) {
                $fieldStats[$field]['matched'] += $plan['fields'][$field]['matched'] ? 1 : 0;
                $fieldStats[$field]['mismatched'] += $plan['fields'][$field]['mismatched'] ? 1 : 0;
                $fieldStats[$field]['null_source'] += $plan['fields'][$field]['null_source'] ? 1 : 0;
                $fieldStats[$field]['null_target'] += $plan['fields'][$field]['null_target'] ? 1 : 0;
            }

            if (! $plan['needs_update']) {
                $alreadyCorrect++;

                continue;
            }

            $wouldUpdateOrders++;
            $repairTargetLegacyIds[] = $legacyId;
            if (count($samples) < 5) {
                $samples[] = [
                    'legacy_id' => $legacyId,
                    'local_id' => $order->id,
                    'changes' => $plan['changes'],
                    'notes_paid_at_source' => $plan['notes_paid_at_source_update'],
                    'map_paid_at_source' => $plan['map_paid_at_source_update'],
                ];
            }

            foreach ($plan['changes'] as $field => $_value) {
                $fieldStats[$field]['would_update']++;
            }
            if ($plan['notes_paid_at_source_update']) {
                $notesPaidAtSourceWouldUpdate++;
            }
            if ($plan['map_paid_at_source_update']) {
                $mapMetadataWouldUpdate++;
            }

            if ($dryRun) {
                continue;
            }

            try {
                $this->applyRepair($order, $map, $plan);
                $updatedOrders++;
                foreach ($plan['changes'] as $field => $_value) {
                    $fieldStats[$field]['updated']++;
                }
                if ($plan['notes_paid_at_source_update']) {
                    $notesPaidAtSourceUpdated++;
                }
                if ($plan['map_paid_at_source_update']) {
                    $mapMetadataUpdated++;
                }
            } catch (Throwable $e) {
                $failed++;
                if (count($samples) < 8) {
                    $samples[] = [
                        'legacy_id' => $legacyId,
                        'local_id' => $order->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        sort($repairTargetLegacyIds, SORT_STRING);

        return [
            'status' => $failed > 0 ? 'partial' : 'ok',
            'entity_type' => 'order_timestamp_repair',
            'dry_run' => $dryRun,
            'migration_run_id' => $run->id,
            'scanned' => $scanned,
            'already_correct' => $alreadyCorrect,
            'would_update_orders' => $dryRun ? $wouldUpdateOrders : 0,
            'updated_orders' => $dryRun ? 0 : $updatedOrders,
            'would_create' => 0,
            'would_delete' => 0,
            'map_changes' => 0,
            'map_rows_created' => 0,
            'map_rows_deleted' => 0,
            'notes_paid_at_source_would_update' => $dryRun ? $notesPaidAtSourceWouldUpdate : 0,
            'notes_paid_at_source_updated' => $dryRun ? 0 : $notesPaidAtSourceUpdated,
            'map_metadata_would_update' => $dryRun ? $mapMetadataWouldUpdate : 0,
            'map_metadata_updated' => $dryRun ? 0 : $mapMetadataUpdated,
            'failed' => $failed,
            'missing_source' => $missingSource,
            'missing_local' => $missingLocal,
            'fields' => $fieldStats,
            'repair_target_legacy_ids_count' => count($repairTargetLegacyIds),
            'repair_target_legacy_ids_sha256' => hash('sha256', implode(',', $repairTargetLegacyIds)),
            'samples' => $samples,
            'wrote_to_database' => ! $dryRun && $updatedOrders > 0,
            'side_effects' => [
                'OrderPaid' => false,
                'OrderFulfilled' => false,
                'Bosta' => false,
                'mail' => false,
                'WhatsApp' => false,
                'payment_gateway' => false,
                'enrollment' => false,
                'suppressed_events' => [OrderPaid::class, OrderFulfilled::class],
            ],
            'contract' => [
                'paid_at_source' => 'date_created_gmt',
                'fulfilled_at_source' => 'date_updated_gmt when wc-completed else null',
                'created_at' => 'not repaired (import wall-clock)',
                'updated_at' => 'not repaired (preserved during repair writes)',
            ],
        ];
    }

    private function requireRunningWordPressRun(int $migrationRunId): LegacyMigrationRun
    {
        $run = LegacyMigrationRun::query()->find($migrationRunId);
        if ($run === null) {
            throw new RuntimeException("Migration run {$migrationRunId} not found.");
        }
        if ($run->source !== LegacyImportMapRepository::SOURCE_WORDPRESS) {
            throw new RuntimeException("Migration run {$migrationRunId} is not a wordpress run.");
        }
        if ($run->status !== MigrationRunService::STATUS_RUNNING) {
            throw new RuntimeException(
                "Migration run {$migrationRunId} is not active (status={$run->status}). Timestamp repair requires a running run."
            );
        }

        return $run;
    }

    /**
     * @return Collection<string|int, object>
     */
    private function loadSourceOrders(): Collection
    {
        $ready = $this->connection->assertReadyForImport('wc_orders');
        if (! $ready['ok'] && ! ($ready['inspect']['probes']['wc_orders'] ?? false)) {
            throw new RuntimeException('WooCommerce HPOS table wp_wc_orders not available for timestamp repair.');
        }

        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();
        $table = $this->connection->table('wc_orders');

        return DB::connection($conn)
            ->table($table)
            ->select(['id', 'status', 'date_created_gmt', 'date_updated_gmt'])
            ->orderBy('id')
            ->get()
            ->keyBy(fn ($row) => (string) $row->id);
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return Collection<string|int, object>
     */
    private function indexSourceOverride(iterable $rows): Collection
    {
        $out = collect();
        foreach ($rows as $row) {
            $id = (string) ($row->id ?? '');
            if ($id !== '') {
                $out->put($id, $row);
            }
        }

        return $out;
    }

    /**
     * @return array{
     *     needs_update: bool,
     *     changes: array<string, CarbonImmutable|null>,
     *     fields: array<string, array{matched: bool, mismatched: bool, null_source: bool, null_target: bool}>,
     *     notes_paid_at_source_update: bool,
     *     map_paid_at_source_update: bool,
     *     expected_paid_at: CarbonImmutable|null,
     *     expected_fulfilled_at: CarbonImmutable|null
     * }
     */
    private function planRepair(Order $order, object $src, LegacyImportMap $map): array
    {
        $expectedPaidAt = $this->importer->normalizeGmtDateTime($src->date_created_gmt ?? null);
        $status = (string) ($src->status ?? '');
        $expectedFulfilledAt = in_array($status, ['wc-completed'], true)
            ? $this->importer->normalizeGmtDateTime($src->date_updated_gmt ?? null)
            : null;

        $actualPaid = $order->paid_at?->clone()->utc();
        $actualFulfilled = $order->fulfilled_at?->clone()->utc();

        $paidMatched = $this->sameUtcInstant($expectedPaidAt, $actualPaid);
        $fulMatched = $this->sameUtcInstant($expectedFulfilledAt, $actualFulfilled);

        $changes = [];
        if (! $paidMatched) {
            $changes['paid_at'] = $expectedPaidAt;
        }
        if (! $fulMatched) {
            $changes['fulfilled_at'] = $expectedFulfilledAt;
        }

        $notes = $this->decodeNotes($order->notes);
        $notesNeedsPaidAtSource = ($notes['paid_at_source'] ?? null) !== 'date_created_gmt';

        $mapMeta = is_array($map->metadata) ? $map->metadata : [];
        $mapNeedsPaidAtSource = ($mapMeta['paid_at_source'] ?? null) !== 'date_created_gmt';

        // created_at / updated_at are import wall-clocks — never repaired against GMT.
        return [
            'needs_update' => $changes !== [] || $notesNeedsPaidAtSource || $mapNeedsPaidAtSource,
            'changes' => $changes,
            'fields' => [
                'paid_at' => [
                    'matched' => $paidMatched,
                    'mismatched' => ! $paidMatched,
                    'null_source' => $expectedPaidAt === null,
                    'null_target' => $actualPaid === null,
                ],
                'fulfilled_at' => [
                    'matched' => $fulMatched,
                    'mismatched' => ! $fulMatched,
                    'null_source' => $expectedFulfilledAt === null,
                    'null_target' => $actualFulfilled === null,
                ],
                'created_at' => [
                    'matched' => true,
                    'mismatched' => false,
                    'null_source' => true,
                    'null_target' => $order->created_at === null,
                ],
                'updated_at' => [
                    'matched' => true,
                    'mismatched' => false,
                    'null_source' => true,
                    'null_target' => $order->updated_at === null,
                ],
            ],
            'notes_paid_at_source_update' => $notesNeedsPaidAtSource,
            'map_paid_at_source_update' => $mapNeedsPaidAtSource,
            'expected_paid_at' => $expectedPaidAt,
            'expected_fulfilled_at' => $expectedFulfilledAt,
        ];
    }

    /**
     * @param  array{
     *     changes: array<string, CarbonImmutable|null>,
     *     notes_paid_at_source_update: bool,
     *     map_paid_at_source_update: bool
     * }  $plan
     */
    private function applyRepair(Order $order, LegacyImportMap $map, array $plan): void
    {
        $this->ensureUtcSessionTimezone();

        Order::withoutEvents(function () use ($order, $map, $plan): void {
            $order->timestamps = false;

            foreach ($plan['changes'] as $field => $value) {
                $order->{$field} = $value;
            }

            if ($plan['notes_paid_at_source_update'] || $plan['changes'] !== []) {
                $notes = $this->decodeNotes($order->notes);
                $notes['paid_at_source'] = 'date_created_gmt';
                $notes['timestamp_repaired'] = true;
                $notes['timestamp_repaired_at'] = now('UTC')->format('Y-m-d H:i:s');
                $order->notes = json_encode($notes, JSON_THROW_ON_ERROR);
            }

            if ($plan['changes'] !== [] || $plan['notes_paid_at_source_update']) {
                $order->save();
            }

            if ($plan['map_paid_at_source_update']) {
                $meta = is_array($map->metadata) ? $map->metadata : [];
                // Preserve ownership flags exactly; only add audit keys.
                $created = $map->wasCreatedByMigration();
                $existing = $map->wasMappedToExisting();
                $meta['paid_at_source'] = 'date_created_gmt';
                $meta['timestamp_repaired'] = true;
                $meta[LegacyImportMapRepository::META_CREATED_BY_MIGRATION] = $created;
                $meta[LegacyImportMapRepository::META_MAPPED_TO_EXISTING] = $existing;
                $map->metadata = $meta;
                $map->save();
            }
        });
    }

    private function sameUtcInstant(?CarbonImmutable $expected, mixed $actual): bool
    {
        if ($expected === null && $actual === null) {
            return true;
        }
        if ($expected === null || $actual === null) {
            return false;
        }

        $actualUtc = $actual instanceof CarbonImmutable
            ? $actual->utc()
            : CarbonImmutable::parse((string) $actual, 'UTC');

        return $expected->utc()->format('Y-m-d H:i:s') === $actualUtc->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNotes(mixed $notes): array
    {
        if (is_array($notes)) {
            return $notes;
        }
        if (! is_string($notes) || $notes === '') {
            return [];
        }
        $decoded = json_decode($notes, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function ensureUtcSessionTimezone(): void
    {
        if ($this->utcSessionEnsured) {
            return;
        }

        try {
            DB::statement("SET time_zone = '+00:00'");
            $this->utcSessionEnsured = true;
        } catch (Throwable) {
            $this->utcSessionEnsured = true;
        }
    }
}
