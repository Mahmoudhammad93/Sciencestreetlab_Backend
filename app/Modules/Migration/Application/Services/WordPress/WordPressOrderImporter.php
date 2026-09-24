<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Support\Str;

/**
 * Order / transaction import from WooCommerce.
 *
 * WooCommerce table+column mappings are BLOCKED_UNTIL_WORDPRESS_DB_DUMP.
 *
 * Historical import rules (enforced by importHistoricalOrderSilently):
 * - Set metadata/notes flag historical_import=true
 * - Set paid_at / fulfilled_at as historical timestamps
 * - MUST NOT dispatch OrderPaid / OrderFulfilled, create shipments, or send mail
 *   (Order::withoutEvents — never call OrderFulfillmentService)
 */
final class WordPressOrderImporter
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        $ready = $this->connection->assertReadyForImport('posts');

        return [
            'status' => 'blocked',
            'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
            'entity_type' => 'order',
            'dry_run' => $dryRun,
            'imported' => 0,
            'skipped' => 0,
            'created' => 0,
            'message' => 'WooCommerce order source SQL is BLOCKED_UNTIL_WORDPRESS_DB_DUMP. Historical imports must use importHistoricalOrderSilently (no OrderPaid/OrderFulfilled).',
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * Create a historical order without firing commerce domain events.
     * Safe for Event::fake assertions that OrderFulfilled / OrderPaid are not dispatched.
     *
     * @param  array{
     *     user_id: int,
     *     subtotal?: float|string,
     *     total?: float|string,
     *     currency?: string,
     *     billing_address?: array<string, mixed>,
     *     shipping_address?: array<string, mixed>,
     *     paid_at?: mixed,
     *     fulfilled_at?: mixed,
     *     status?: string
     * }  $attributes
     * @return array{order: Order|null, map: LegacyImportMap|null, created: bool, dry_run: bool}
     */
    public function importHistoricalOrderSilently(
        string $legacyId,
        array $attributes,
        bool $dryRun = false,
    ): array {
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
                'map' => $this->maps->findOrCreate('order', $legacyId, [
                    'metadata' => ['dry_run' => true, 'historical_import' => true],
                ]),
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

        $now = now();
        $subtotal = $attributes['subtotal'] ?? $attributes['total'] ?? 0;
        $total = $attributes['total'] ?? $subtotal;

        /** @var Order $order */
        $order = Order::withoutEvents(function () use ($attributes, $emptyAddress, $now, $subtotal, $total): Order {
            // Do not call OrderFulfillmentService — that would dispatch OrderPaid / OrderFulfilled.
            // withoutEvents also skips model creating hooks, so set uuid/order_number explicitly.
            return Order::query()->create([
                'uuid' => (string) Str::uuid(),
                'order_number' => 'WP-'.strtoupper(Str::random(8)),
                'user_id' => $attributes['user_id'],
                'status' => $attributes['status'] ?? OrderStatus::Paid->value,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'tax_amount' => 0,
                'total' => $total,
                'currency' => $attributes['currency'] ?? 'EGP',
                'billing_address' => $attributes['billing_address'] ?? $emptyAddress,
                'shipping_address' => $attributes['shipping_address'] ?? $emptyAddress,
                'notes' => json_encode([
                    'historical_import' => true,
                    'password_strategy' => null,
                    'source' => 'wordpress',
                ], JSON_THROW_ON_ERROR),
                'paid_at' => $attributes['paid_at'] ?? $now,
                'fulfilled_at' => $attributes['fulfilled_at'] ?? $now,
                'requires_delivery_fulfillment' => false,
            ]);
        });

        $map = $this->maps->upsertMapping('order', $legacyId, [
            'local_id' => $order->id,
            'imported_at' => $now,
            'metadata' => [
                'historical_import' => true,
                'events_dispatched' => false,
                'suppressed_events' => [OrderPaid::class, OrderFulfilled::class],
            ],
        ]);

        return ['order' => $order, 'map' => $map, 'created' => true, 'dry_run' => false];
    }
}
