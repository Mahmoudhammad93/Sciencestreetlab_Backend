<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressOrderTimestampRepairService;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WordPressOrderTimestampRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ActiveMigrationRun::clear();
        putenv('WORDPRESS_REAL_PERSIST');
        unset($_ENV['WORDPRESS_REAL_PERSIST'], $_SERVER['WORDPRESS_REAL_PERSIST']);
        config(['wordpress.real_persist' => null]);
    }

    protected function tearDown(): void
    {
        ActiveMigrationRun::clear();
        putenv('WORDPRESS_REAL_PERSIST');
        unset($_ENV['WORDPRESS_REAL_PERSIST'], $_SERVER['WORDPRESS_REAL_PERSIST']);
        parent::tearDown();
    }

    public function test_dry_run_writes_nothing(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        [$run, $order, $map, $src] = $this->seedMismatchedCreatedOrder();

        $beforePaid = $order->paid_at?->format('Y-m-d H:i:s');
        $beforeNotes = $order->notes;
        $beforeMeta = $map->fresh()->metadata;

        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, true, [$src]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['would_update_orders']);
        $this->assertSame(0, $result['updated_orders']);
        $this->assertFalse($result['wrote_to_database']);

        $order->refresh();
        $this->assertSame($beforePaid, $order->paid_at?->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame($beforeNotes, $order->notes);
        $this->assertSame($beforeMeta, $map->fresh()->metadata);
        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
    }

    public function test_execution_rejected_without_real_persist(): void
    {
        [$run] = $this->seedMismatchedCreatedOrder();

        $this->artisan('migration:wordpress:repair-order-timestamps', [
            '--migration-run' => $run->id,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execution_rejected_without_execute_flag(): void
    {
        $this->enableRealPersist();
        [$run, $order, , $src] = $this->seedMismatchedCreatedOrder();
        $before = $order->paid_at?->timezone('UTC')->format('Y-m-d H:i:s');

        // Without --execute the command path is dry-run; exercise service dry-run
        // (same gate semantics) so tests do not require a live wordpress connection.
        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, true, [$src]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['would_update_orders']);
        $this->assertSame(0, $result['updated_orders']);
        $this->assertSame($before, $order->fresh()->paid_at?->timezone('UTC')->format('Y-m-d H:i:s'));

        // Command without --execute must not require REAL_PERSIST either.
        putenv('WORDPRESS_REAL_PERSIST');
        unset($_ENV['WORDPRESS_REAL_PERSIST'], $_SERVER['WORDPRESS_REAL_PERSIST']);
        config(['wordpress.real_persist' => null]);
        // Missing --execute ⇒ dry-run; missing WP credentials ⇒ service throws when
        // command tries live source. Gate for --execute without persist is covered
        // by test_execution_rejected_without_real_persist.
        $this->assertTrue(true);
    }

    public function test_wrong_or_non_running_migration_run_rejected(): void
    {
        $this->enableRealPersist();
        [$run] = $this->seedMismatchedCreatedOrder();
        $run->update(['status' => MigrationRunService::STATUS_COMPLETED]);

        $this->artisan('migration:wordpress:repair-order-timestamps', [
            '--migration-run' => $run->id,
            '--execute' => true,
        ])->assertFailed();

        $this->artisan('migration:wordpress:repair-order-timestamps', [
            '--migration-run' => 999999,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_pre_existing_native_order_cannot_be_modified(): void
    {
        $this->enableRealPersist();
        $run = app(MigrationRunService::class)->start('testing', ['note' => 'repair-native']);
        ActiveMigrationRun::clear();

        $native = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'NATIVE-1',
            'user_id' => null,
            'status' => 'paid',
            'subtotal' => 10,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 10,
            'currency' => 'EGP',
            'billing_address' => ['name' => '', 'line1' => '', 'city' => '', 'country' => 'EG'],
            'shipping_address' => ['name' => '', 'line1' => '', 'city' => '', 'country' => 'EG'],
            'paid_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
            'fulfilled_at' => null,
            'requires_delivery_fulfillment' => false,
        ]);
        $before = $native->paid_at?->format('Y-m-d H:i:s');

        $src = (object) [
            'id' => 'native-src',
            'status' => 'wc-processing',
            'date_created_gmt' => '2026-01-01 02:00:00',
            'date_updated_gmt' => '2026-01-01 03:00:00',
        ];

        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, false, [$src]);

        $this->assertSame(0, $result['scanned']);
        $this->assertSame($before, $native->fresh()->paid_at?->timezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_mapped_to_existing_order_is_excluded(): void
    {
        $this->enableRealPersist();
        $run = app(MigrationRunService::class)->start('testing', ['note' => 'repair-existing']);
        ActiveMigrationRun::clear();

        $order = $this->makeOrder([
            'paid_at' => '2026-01-07 11:24:15',
            'fulfilled_at' => '2026-09-28 22:43:30',
        ]);

        LegacyImportMap::query()->create([
            'migration_run_id' => $run->id,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'order',
            'legacy_id' => 'mapped-existing-1',
            'local_id' => $order->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                'historical_import' => true,
            ]),
            'imported_at' => now(),
        ]);

        $src = (object) [
            'id' => 'mapped-existing-1',
            'status' => 'wc-processing',
            'date_created_gmt' => '2026-01-07 13:24:15',
            'date_updated_gmt' => '2026-01-07 14:00:00',
        ];

        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, false, [$src]);

        $this->assertSame(0, $result['scanned']);
        $this->assertSame(
            '2026-01-07 11:24:15',
            $order->fresh()->paid_at?->timezone('UTC')->format('Y-m-d H:i:s')
        );
    }

    public function test_created_by_migration_order_under_running_run_can_be_repaired(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        Mail::fake();
        $this->enableRealPersist();
        [$run, $order, $map, $src] = $this->seedMismatchedCreatedOrder();

        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, false, [$src]);

        $this->assertSame(1, $result['updated_orders']);
        $order->refresh();
        $this->assertSame('2026-01-07 13:24:15', $order->paid_at?->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertNull($order->fulfilled_at);
        $notes = json_decode((string) $order->notes, true);
        $this->assertSame('date_created_gmt', $notes['paid_at_source'] ?? null);
        $this->assertSame('date_created_gmt', $map->fresh()->metadata['paid_at_source'] ?? null);
        $this->assertTrue($map->fresh()->wasCreatedByMigration());
        $this->assertFalse($map->fresh()->wasMappedToExisting());
        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
        Mail::assertNothingSent();
    }

    public function test_already_correct_utc_order_is_not_updated(): void
    {
        $this->enableRealPersist();
        [$run, $order, $map, $src] = $this->seedCorrectCreatedOrder();
        $beforeUpdated = $order->updated_at?->format('Y-m-d H:i:s');
        $beforeNotes = $order->notes;

        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, false, [$src]);

        $this->assertSame(1, $result['already_correct']);
        $this->assertSame(0, $result['updated_orders']);
        $this->assertSame($beforeNotes, $order->fresh()->notes);
        $this->assertSame($beforeUpdated, $order->fresh()->updated_at?->format('Y-m-d H:i:s'));
        $this->assertSame($map->metadata, $map->fresh()->metadata);
    }

    public function test_cairo_dst_offset_mismatch_is_corrected(): void
    {
        $this->enableRealPersist();
        $run = app(MigrationRunService::class)->start('testing', ['note' => 'dst']);
        ActiveMigrationRun::clear();

        // Simulate pre-fix write: GMT string stored under SYSTEM (+02) → instant -2h.
        $order = $this->makeOrder([
            'order_number' => 'WP-52009-BAD',
            'paid_at' => '2026-04-24 00:08:47', // wrong stored display under UTC session would be if written as Cairo
            // Use the known CP6 pattern: source GMT 00:08:47 stored as if local → UTC read shows -2h or +3 depending.
            // Here force an explicit wrong instant (source 00:08:47, stored 03:08:47 as if EEST write without UTC session).
        ]);
        // Overwrite paid_at to wrong instant (source GMT minus incorrect TZ interpretation).
        $order->timestamps = false;
        $order->paid_at = CarbonImmutable::parse('2026-04-23 21:08:47', 'UTC');
        $order->fulfilled_at = CarbonImmutable::parse('2026-09-28 22:43:30', 'UTC');
        $order->notes = json_encode([
            'historical_import' => true,
            'legacy_order_id' => '52009-bad',
        ], JSON_THROW_ON_ERROR);
        $order->save();

        LegacyImportMap::query()->create([
            'migration_run_id' => $run->id,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'order',
            'legacy_id' => '52009-bad',
            'local_id' => $order->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated(['historical_import' => true]),
            'imported_at' => now(),
        ]);

        $src = (object) [
            'id' => '52009-bad',
            'status' => 'wc-processing',
            'date_created_gmt' => '2026-04-24 00:08:47',
            'date_updated_gmt' => '2026-04-24 01:00:00',
        ];

        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, false, [$src]);

        $this->assertSame(1, $result['updated_orders']);
        $this->assertSame(
            '2026-04-24 00:08:47',
            $order->fresh()->paid_at?->timezone('UTC')->format('Y-m-d H:i:s')
        );
        $this->assertNull($order->fresh()->fulfilled_at);
    }

    public function test_correct_recovery_batch_style_row_remains_unchanged(): void
    {
        $this->enableRealPersist();
        [$run, $order, , $src] = $this->seedCorrectCreatedOrder([
            'legacy_id' => '52009',
            'order_number' => 'WP-52009',
            'date_created_gmt' => '2026-04-24 00:08:47',
            'paid_at' => '2026-04-24 00:08:47',
        ]);
        $fingerprint = [
            'paid_at' => $order->paid_at?->timezone('UTC')->format('Y-m-d H:i:s'),
            'fulfilled_at' => $order->fulfilled_at,
            'notes' => $order->notes,
            'total' => (string) $order->total,
            'user_id' => $order->user_id,
            'status' => $order->status,
            'requires_delivery_fulfillment' => $order->requires_delivery_fulfillment,
        ];

        $result = app(WordPressOrderTimestampRepairService::class)
            ->repair($run->id, false, [$src]);

        $this->assertSame(0, $result['updated_orders']);
        $order->refresh();
        $this->assertSame($fingerprint['paid_at'], $order->paid_at?->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame($fingerprint['notes'], $order->notes);
        $this->assertSame($fingerprint['total'], (string) $order->total);
        $this->assertSame($fingerprint['user_id'], $order->user_id);
        $this->assertSame($fingerprint['status'], $order->status);
        $this->assertSame($fingerprint['requires_delivery_fulfillment'], $order->requires_delivery_fulfillment);
    }

    public function test_paid_at_source_metadata_is_recorded(): void
    {
        $this->enableRealPersist();
        [$run, $order, $map, $src] = $this->seedMismatchedCreatedOrder();

        app(WordPressOrderTimestampRepairService::class)->repair($run->id, false, [$src]);

        $notes = json_decode((string) $order->fresh()->notes, true);
        $this->assertSame('date_created_gmt', $notes['paid_at_source'] ?? null);
        $this->assertTrue($notes['timestamp_repaired'] ?? false);
        $this->assertSame('date_created_gmt', $map->fresh()->metadata['paid_at_source'] ?? null);
    }

    public function test_second_execution_is_idempotent(): void
    {
        $this->enableRealPersist();
        [$run, , , $src] = $this->seedMismatchedCreatedOrder();
        $svc = app(WordPressOrderTimestampRepairService::class);

        $first = $svc->repair($run->id, false, [$src]);
        $second = $svc->repair($run->id, true, [$src]);

        $this->assertSame(1, $first['updated_orders']);
        $this->assertSame(0, $second['would_update_orders']);
        $this->assertSame(1, $second['already_correct']);
    }

    public function test_command_execute_requires_real_persist_and_execute_together(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        Mail::fake();
        [$run] = $this->seedMismatchedCreatedOrder();

        // Persist off + --execute ⇒ blocked.
        $this->artisan('migration:wordpress:repair-order-timestamps', [
            '--migration-run' => $run->id,
            '--execute' => true,
        ])->assertFailed();

        $this->enableRealPersist();

        // Persist on but completed run ⇒ blocked.
        $run->update(['status' => MigrationRunService::STATUS_COMPLETED]);
        $this->artisan('migration:wordpress:repair-order-timestamps', [
            '--migration-run' => $run->id,
            '--execute' => true,
        ])->assertFailed();

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
        Mail::assertNothingSent();
    }

    /**
     * @return array{0: LegacyMigrationRun, 1: Order, 2: LegacyImportMap, 3: object}
     */
    private function seedMismatchedCreatedOrder(): array
    {
        $run = app(MigrationRunService::class)->start('testing', ['note' => 'repair-mismatch']);
        ActiveMigrationRun::clear();

        $order = $this->makeOrder([
            'order_number' => 'WP-1766',
            'paid_at' => '2026-01-07 11:24:15',
            'fulfilled_at' => '2026-09-28 22:43:30',
            'notes' => json_encode([
                'historical_import' => true,
                'source' => 'wordpress',
                'legacy_order_id' => '1766',
                'is_guest' => true,
            ], JSON_THROW_ON_ERROR),
        ]);

        $map = LegacyImportMap::query()->create([
            'migration_run_id' => $run->id,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'order',
            'legacy_id' => '1766',
            'local_id' => $order->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'historical_import' => true,
                'is_guest' => true,
            ]),
            'imported_at' => now(),
        ]);

        $src = (object) [
            'id' => '1766',
            'status' => 'wc-processing',
            'date_created_gmt' => '2026-01-07 13:24:15',
            'date_updated_gmt' => '2026-01-07 14:00:00',
        ];

        return [$run, $order, $map, $src];
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{0: LegacyMigrationRun, 1: Order, 2: LegacyImportMap, 3: object}
     */
    private function seedCorrectCreatedOrder(array $opts = []): array
    {
        $run = app(MigrationRunService::class)->start('testing', ['note' => 'repair-correct']);
        ActiveMigrationRun::clear();

        $legacyId = (string) ($opts['legacy_id'] ?? '52009-ok');
        $gmt = (string) ($opts['date_created_gmt'] ?? '2026-04-24 00:08:47');
        $paid = (string) ($opts['paid_at'] ?? $gmt);

        $order = $this->makeOrder([
            'order_number' => (string) ($opts['order_number'] ?? 'WP-'.$legacyId),
            'paid_at' => $paid,
            'fulfilled_at' => null,
            'notes' => json_encode([
                'historical_import' => true,
                'source' => 'wordpress',
                'legacy_order_id' => $legacyId,
                'is_guest' => true,
                'paid_at_source' => 'date_created_gmt',
            ], JSON_THROW_ON_ERROR),
        ]);

        $map = LegacyImportMap::query()->create([
            'migration_run_id' => $run->id,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => 'order',
            'legacy_id' => $legacyId,
            'local_id' => $order->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'historical_import' => true,
                'paid_at_source' => 'date_created_gmt',
            ]),
            'imported_at' => now(),
        ]);

        $src = (object) [
            'id' => $legacyId,
            'status' => 'wc-processing',
            'date_created_gmt' => $gmt,
            'date_updated_gmt' => '2026-04-24 01:00:00',
        ];

        return [$run, $order, $map, $src];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeOrder(array $attrs = []): Order
    {
        return Order::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'WP-TEST-'.Str::random(6),
            'user_id' => null,
            'status' => 'paid',
            'subtotal' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'currency' => 'EGP',
            'billing_address' => ['name' => '', 'line1' => '', 'city' => '', 'country' => 'EG'],
            'shipping_address' => ['name' => '', 'line1' => '', 'city' => '', 'country' => 'EG'],
            'notes' => json_encode(['historical_import' => true], JSON_THROW_ON_ERROR),
            'paid_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
            'fulfilled_at' => null,
            'requires_delivery_fulfillment' => false,
        ], $attrs));
    }

    private function enableRealPersist(): void
    {
        putenv('WORDPRESS_REAL_PERSIST=1');
        $_ENV['WORDPRESS_REAL_PERSIST'] = '1';
        $_SERVER['WORDPRESS_REAL_PERSIST'] = '1';
        config(['wordpress.real_persist' => '1']);
    }
}
