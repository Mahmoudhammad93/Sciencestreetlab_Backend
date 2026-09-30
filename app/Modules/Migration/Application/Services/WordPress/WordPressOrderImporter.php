<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * WooCommerce HPOS historical order import (wp_wc_orders).
 *
 * Guest policy (customer_id=0):
 * - Import with orders.user_id = NULL (schema nullable).
 * - Preserve billing/shipping + billing_email.
 * - Do NOT create synthetic users.
 * - Do NOT attach by billing_email match.
 * - Keep WP customer_id=0 in map metadata.
 *
 * Timestamps:
 * - HPOS date_*_gmt values are UTC wall-clock strings.
 * - MySQL TIMESTAMP interprets naive strings in session time_zone.
 * - Host SYSTEM TZ Africa/Cairo has DST gaps (e.g. 2026-04-24 00:xx) that
 *   reject valid UTC GMT strings if session TZ remains SYSTEM.
 * - Historical import forces session time_zone=+00:00 and parses GMT as UTC.
 *
 * paid_at mapping:
 * - Source dump has no date_paid_gmt; paid_at uses date_created_gmt (UTC) as
 *   best-available historical activity timestamp (not a gateway capture time).
 *
 * Dry-run writes NOTHING.
 * Real path uses Order::withoutEvents — no OrderPaid/OrderFulfilled/Bosta/mail.
 */
final class WordPressOrderImporter
{
    private bool $utcSessionEnsured = false;
    /** @var array<string, string> */
    private const STATUS_MAP = [
        'wc-completed' => 'delivered',
        'wc-processing' => 'paid',
        'wc-cancelled' => 'cancelled',
        'wc-failed' => 'cancelled',
        'wc-refunded' => 'refunded',
        'wc-pending' => 'awaiting_payment',
        'wc-on-hold' => 'awaiting_payment',
    ];

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'order')) {
            return $block;
        }

        $ready = $this->connection->assertReadyForImport('wc_orders');
        if (! $ready['ok']) {
            $ready = $this->connection->assertReadyForImport('posts');
            if (! ($ready['inspect']['probes']['wc_orders'] ?? false)) {
                return [
                    'status' => 'blocked',
                    'code' => WordPressConnectionService::BLOCKED,
                    'entity_type' => 'order',
                    'dry_run' => $dryRun,
                    'imported' => 0,
                    'created' => 0,
                    'wrote_to_database' => false,
                    'message' => 'WooCommerce HPOS table wp_wc_orders not available.',
                    'inspect' => $ready['inspect'],
                ];
            }
        }

        $this->connection->assertProductionPrefix();
        $this->ensureUtcSessionTimezone();
        $conn = $this->connection->connectionName();
        $ordersTable = $this->connection->table('wc_orders');
        $addrTable = $this->connection->table('wc_order_addresses');

        $byStatus = [];
        foreach (DB::connection($conn)->table($ordersTable)->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->get() as $row) {
            $byStatus[(string) $row->status] = (int) $row->c;
        }

        $scanned = 0;
        $wouldCreate = 0;
        $wouldCreateGuest = 0;
        $wouldCreateRegistered = 0;
        $wouldSkipMapped = 0;
        $wouldDeferMissingUserMap = 0;
        $created = 0;
        $guestSamples = [];
        $samples = [];

        DB::connection($conn)
            ->table($ordersTable)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (
                $dryRun,
                &$scanned,
                &$wouldCreate,
                &$wouldCreateGuest,
                &$wouldCreateRegistered,
                &$wouldSkipMapped,
                &$wouldDeferMissingUserMap,
                &$created,
                &$samples,
                &$guestSamples,
                $addrTable,
                $conn,
            ): void {
                foreach ($rows as $row) {
                    $scanned++;
                    $legacyId = (string) $row->id;

                    if ($this->maps->find('order', $legacyId)?->local_id) {
                        $wouldSkipMapped++;

                        continue;
                    }

                    $customerId = (int) ($row->customer_id ?? 0);
                    $isGuest = $customerId <= 0;
                    $userMap = ! $isGuest ? $this->maps->find('user', (string) $customerId) : null;
                    $localUserId = $userMap?->local_id;

                    if ($dryRun) {
                        if ($isGuest) {
                            $wouldCreateGuest++;
                            $wouldCreate++;
                        } elseif ($localUserId === null) {
                            // Registered WP customer but Laravel user map not yet present.
                            $wouldDeferMissingUserMap++;
                            $wouldCreate++; // still counted as importable once users imported
                        } else {
                            $wouldCreateRegistered++;
                            $wouldCreate++;
                        }

                        $billing = $this->loadAddress($conn, $addrTable, (int) $row->id, 'billing');
                        $sample = [
                            'legacy_id' => $legacyId,
                            'status' => $row->status,
                            'mapped_status' => $this->mapStatus((string) $row->status),
                            'total' => $row->total_amount,
                            'currency' => $row->currency,
                            'customer_id' => $customerId,
                            'ownership' => $isGuest ? 'guest_user_id_null' : 'registered_via_user_map',
                            'billing_email' => $row->billing_email,
                            'billing_email_auto_link' => false,
                            'payment_method' => $row->payment_method,
                            'date_created_gmt' => $row->date_created_gmt,
                            'billing_city' => $billing['city'] ?? null,
                            'side_effects' => 'none (historical silent)',
                        ];
                        if (count($samples) < 5) {
                            $samples[] = $sample;
                        }
                        if ($isGuest && count($guestSamples) < 3) {
                            $guestSamples[] = $sample;
                        }

                        continue;
                    }

                    if (! $isGuest && $localUserId === null) {
                        $wouldDeferMissingUserMap++;

                        continue;
                    }

                    $billing = $this->loadAddress($conn, $addrTable, (int) $row->id, 'billing');
                    if (($billing['email'] ?? '') === '' && is_string($row->billing_email) && $row->billing_email !== '') {
                        $billing['email'] = $row->billing_email;
                    }
                    $shipping = $this->loadAddress($conn, $addrTable, (int) $row->id, 'shipping') ?: $billing;

                    $paidAt = $this->normalizeGmtDateTime($row->date_created_gmt ?? null);
                    $fulfilledAt = in_array($row->status, ['wc-completed'], true)
                        ? $this->normalizeGmtDateTime($row->date_updated_gmt ?? null)
                        : null;

                    $result = $this->importHistoricalOrderSilently($legacyId, [
                        'user_id' => $isGuest ? null : $localUserId,
                        'subtotal' => $row->total_amount,
                        'total' => $row->total_amount,
                        'currency' => $row->currency ?: 'EGP',
                        'billing_address' => $billing,
                        'shipping_address' => $shipping,
                        'billing_email' => $row->billing_email,
                        'paid_at' => $paidAt,
                        'fulfilled_at' => $fulfilledAt,
                        'status' => $this->mapStatus((string) $row->status),
                        'order_number' => 'WP-'.$legacyId,
                        'wp_customer_id' => $customerId,
                        'is_guest' => $isGuest,
                        'paid_at_source' => 'date_created_gmt',
                    ], false);

                    if ($result['created']) {
                        $created++;
                    } else {
                        $wouldSkipMapped++;
                    }
                }
            }, 'id');

        return [
            'status' => 'ok',
            'entity_type' => 'order',
            'dry_run' => $dryRun,
            'source' => 'wp_wc_orders (HPOS)',
            'by_status' => $byStatus,
            'scanned' => $scanned,
            'historical_orders_considered' => $scanned,
            'would_create' => $wouldCreate,
            'would_create_guest_null_user' => $wouldCreateGuest,
            'would_create_registered' => $wouldCreateRegistered,
            'would_defer_missing_user_map' => $wouldDeferMissingUserMap,
            'would_skip_mapped' => $wouldSkipMapped,
            'guest_policy' => [
                'customer_id_0' => 'import with user_id=null',
                'synthetic_users' => false,
                'billing_email_auto_link' => false,
                'preserve_billing_shipping' => true,
                'legacy_map' => true,
                'account_history_visibility' => 'hidden (API filters by auth user_id)',
            ],
            'created' => $dryRun ? 0 : $created,
            'imported' => $dryRun ? 0 : $created,
            'samples' => $samples,
            'guest_samples' => $guestSamples,
            'wrote_to_database' => ! $dryRun && $created > 0,
            'side_effects' => [
                'OrderPaid' => false,
                'OrderFulfilled' => false,
                'Bosta' => false,
                'mail' => false,
                'WhatsApp' => false,
                'payment_gateway' => false,
                'enrollment' => false,
            ],
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * @param  array{
     *     user_id: int|null,
     *     subtotal?: float|string,
     *     total?: float|string,
     *     currency?: string,
     *     billing_address?: array<string, mixed>,
     *     shipping_address?: array<string, mixed>,
     *     billing_email?: string|null,
     *     paid_at?: mixed,
     *     fulfilled_at?: mixed,
     *     status?: string,
     *     order_number?: string,
     *     wp_customer_id?: int,
     *     is_guest?: bool,
     *     paid_at_source?: string|null
     * }  $attributes
     * @return array{order: Order|null, map: LegacyImportMap|null, created: bool, dry_run: bool}
     */
    public function importHistoricalOrderSilently(
        string $legacyId,
        array $attributes,
        bool $dryRun = false,
    ): array {
        $this->ensureUtcSessionTimezone();

        $existing = $this->maps->find('order', $legacyId);
        if ($existing?->local_id) {
            $order = Order::query()->find($existing->local_id);
            if ($order !== null) {
                return ['order' => $order, 'map' => $existing, 'created' => false, 'dry_run' => $dryRun];
            }
        }

        if ($dryRun) {
            return [
                'order' => null,
                'map' => null,
                'created' => false,
                'dry_run' => true,
            ];
        }

        $emptyAddress = [
            'name' => '',
            'line1' => '',
            'city' => '',
            'country' => 'EG',
        ];

        $now = now('UTC');
        $subtotal = $attributes['subtotal'] ?? $attributes['total'] ?? 0;
        $total = $attributes['total'] ?? $subtotal;
        $isGuest = (bool) ($attributes['is_guest'] ?? ($attributes['user_id'] ?? null) === null);
        $paidAt = $this->coerceUtcDateTime($attributes['paid_at'] ?? null) ?? $now;
        $fulfilledAt = array_key_exists('fulfilled_at', $attributes)
            ? $this->coerceUtcDateTime($attributes['fulfilled_at'])
            : $now;

        /** @var Order $order */
        $order = Order::withoutEvents(function () use ($attributes, $emptyAddress, $now, $subtotal, $total, $legacyId, $isGuest, $paidAt, $fulfilledAt): Order {
            $billing = $attributes['billing_address'] ?? $emptyAddress;
            if (! empty($attributes['billing_email']) && empty($billing['email'])) {
                $billing['email'] = $attributes['billing_email'];
            }

            return Order::query()->create([
                'uuid' => (string) Str::uuid(),
                'order_number' => $attributes['order_number'] ?? ('WP-'.$legacyId),
                'user_id' => $attributes['user_id'] ?? null,
                'status' => $attributes['status'] ?? OrderStatus::Paid->value,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'tax_amount' => 0,
                'total' => $total,
                'currency' => $attributes['currency'] ?? 'EGP',
                'billing_address' => $billing,
                'shipping_address' => $attributes['shipping_address'] ?? $billing,
                'notes' => json_encode([
                    'historical_import' => true,
                    'source' => 'wordpress',
                    'legacy_order_id' => $legacyId,
                    'wp_customer_id' => $attributes['wp_customer_id'] ?? ($isGuest ? 0 : null),
                    'is_guest' => $isGuest,
                    'billing_email' => $attributes['billing_email'] ?? ($billing['email'] ?? null),
                    'paid_at_source' => $attributes['paid_at_source'] ?? 'date_created_gmt',
                ], JSON_THROW_ON_ERROR),
                'paid_at' => $paidAt,
                'fulfilled_at' => $fulfilledAt,
                'requires_delivery_fulfillment' => false,
            ]);
        });

        $map = $this->maps->upsertMapping('order', $legacyId, [
            'local_id' => $order->id,
            'legacy_email' => $attributes['billing_email'] ?? null,
            'imported_at' => $now,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'historical_import' => true,
                'is_guest' => $isGuest,
                'wp_customer_id' => $attributes['wp_customer_id'] ?? ($isGuest ? 0 : null),
                'paid_at_source' => $attributes['paid_at_source'] ?? 'date_created_gmt',
                'events_dispatched' => false,
                'suppressed_events' => [OrderPaid::class, OrderFulfilled::class],
            ]),
        ]);

        return ['order' => $order, 'map' => $map, 'created' => true, 'dry_run' => false];
    }

    /**
     * Parse a WooCommerce *_gmt DATETIME string as UTC.
     *
     * Public for focused regression tests.
     */
    public function normalizeGmtDateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        $raw = trim((string) $value);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }

        return CarbonImmutable::parse($raw, 'UTC');
    }

    /**
     * Force MySQL session TZ to UTC so TIMESTAMP columns accept GMT strings.
     *
     * Scoped to the Laravel default connection used for Order persistence.
     * Does not change global MySQL settings or STRICT_TRANS_TABLES.
     */
    public function ensureUtcSessionTimezone(): void
    {
        if ($this->utcSessionEnsured) {
            return;
        }

        try {
            DB::statement("SET time_zone = '+00:00'");
            $this->utcSessionEnsured = true;
        } catch (Throwable) {
            // SQLite / non-MySQL test drivers: no session time_zone.
            $this->utcSessionEnsured = true;
        }
    }

    private function coerceUtcDateTime(mixed $value): ?CarbonImmutable
    {
        return $this->normalizeGmtDateTime($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAddress(string $connection, string $table, int $orderId, string $type): array
    {
        $row = DB::connection($connection)
            ->table($table)
            ->where('order_id', $orderId)
            ->where('address_type', $type)
            ->first();

        if ($row === null) {
            return [
                'name' => '',
                'line1' => '',
                'city' => '',
                'country' => 'EG',
            ];
        }

        return [
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
            'company' => $row->company,
            'line1' => $row->address_1,
            'line2' => $row->address_2,
            'city' => $row->city,
            'state' => $row->state,
            'postcode' => $row->postcode,
            'country' => $row->country ?: 'EG',
            'email' => $row->email,
            'phone' => $row->phone,
        ];
    }

    private function mapStatus(string $wcStatus): string
    {
        return self::STATUS_MAP[$wcStatus] ?? OrderStatus::Paid->value;
    }
}
