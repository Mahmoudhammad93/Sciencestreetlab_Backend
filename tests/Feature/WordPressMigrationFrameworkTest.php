<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressAuditService;
use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use App\Modules\Migration\Application\Services\WordPress\WordPressCourseImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressOrderImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressUserImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

final class WordPressMigrationFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_command_reports_blocked_without_credentials(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $this->artisan('migration:wordpress:inspect')
            ->expectsOutputToContain('BLOCKED_UNTIL_WORDPRESS_DB_DUMP')
            ->assertSuccessful();
    }

    public function test_production_prefix_wp_is_accepted(): void
    {
        config(['wordpress.prefix' => 'wp_']);
        app(WordPressConnectionService::class)->assertProductionPrefix();
        $this->assertSame('wp_', app(WordPressConnectionService::class)->tablePrefix());
    }

    public function test_qa_prefix_wp_3_is_rejected(): void
    {
        config(['wordpress.prefix' => 'wp_3_']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_3_');
        app(WordPressConnectionService::class)->assertProductionPrefix();
    }

    public function test_orphan_prefix_wp_2_is_rejected(): void
    {
        config(['wordpress.prefix' => 'wp_2_']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_2_');
        app(WordPressConnectionService::class)->assertProductionPrefix();
    }

    public function test_users_dry_run_does_not_create_users_when_blocked(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $beforeUsers = User::query()->count();
        $beforeMaps = LegacyImportMap::query()->count();

        $this->artisan('migration:wordpress:users', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
    }

    public function test_user_silent_import_never_stores_wp_hash_and_is_idempotent(): void
    {
        $importer = app(WordPressUserImporter::class);

        $first = $importer->importUserSilently('wp-1', [
            'email' => 'legacy@example.com',
            'name' => 'Legacy User',
        ]);
        $second = $importer->importUserSilently('wp-1', [
            'email' => 'legacy@example.com',
            'name' => 'Legacy User',
        ]);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertSame(1, User::query()->where('email', 'legacy@example.com')->count());
        $this->assertSame('reset_required', $first['map']->metadata['password_strategy'] ?? null);
        $this->assertNotSame('$P$wordpress-hash', $first['user']->password);
    }

    public function test_user_dry_run_silent_helper_writes_nothing(): void
    {
        $beforeUsers = User::query()->count();
        $beforeMaps = LegacyImportMap::query()->count();

        $result = app(WordPressUserImporter::class)->importUserSilently('wp-dry', [
            'email' => 'dry@example.com',
            'name' => 'Dry',
        ], true);

        $this->assertFalse($result['created']);
        $this->assertNull($result['map']);
        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
    }

    public function test_map_upsert_is_idempotent(): void
    {
        $repo = app(LegacyImportMapRepository::class);
        $first = $repo->upsertMapping('course', '99', ['local_id' => 1]);
        $second = $repo->upsertMapping('course', '99', ['local_id' => 1]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '99')->count());
    }

    public function test_guest_historical_order_imports_with_null_user_no_synthetic_user(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        Mail::fake();

        $beforeUsers = User::query()->count();
        $importer = app(WordPressOrderImporter::class);

        $result = $importer->importHistoricalOrderSilently('wc-guest-1', [
            'user_id' => null,
            'is_guest' => true,
            'wp_customer_id' => 0,
            'billing_email' => 'guest-hist@example.com',
            'total' => 99,
            'billing_address' => [
                'name' => 'Guest Buyer',
                'email' => 'guest-hist@example.com',
                'line1' => 'Street 1',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
            'shipping_address' => [
                'name' => 'Guest Buyer',
                'line1' => 'Street 1',
                'city' => 'Cairo',
                'country' => 'EG',
            ],
        ]);

        $this->assertTrue($result['created']);
        $this->assertNull($result['order']->user_id);
        $this->assertFalse($result['order']->requires_delivery_fulfillment);
        $this->assertSame('guest-hist@example.com', $result['order']->billing_address['email'] ?? null);
        $this->assertStringContainsString('"is_guest":true', (string) $result['order']->notes);
        $this->assertStringContainsString('"wp_customer_id":0', (string) $result['order']->notes);
        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertTrue($result['map']->metadata['is_guest'] ?? false);

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
        Mail::assertNothingSent();
    }

    public function test_guest_historical_order_hidden_from_authenticated_order_history_query(): void
    {
        $user = User::factory()->create();
        $importer = app(WordPressOrderImporter::class);

        $importer->importHistoricalOrderSilently('wc-guest-hidden', [
            'user_id' => null,
            'is_guest' => true,
            'wp_customer_id' => 0,
            'billing_email' => $user->email, // even matching email must NOT auto-link
            'total' => 10,
        ]);

        $visible = Order::query()->where('user_id', $user->id)->count();
        $this->assertSame(0, $visible);
        $this->assertSame(1, Order::query()->whereNull('user_id')->count());
    }

    public function test_sku_wp_id_and_variable_skip_documented_in_product_resolver(): void
    {
        $importer = app(WordPressProductImporter::class);
        $this->assertSame('WP-8500', $importer->resolveSku(null, '8500'));
        $this->assertSame('KEEP', $importer->resolveSku('KEEP', '8500'));
    }

    public function test_historical_order_import_does_not_dispatch_fulfillment_events(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        Mail::fake();

        $user = User::factory()->create();
        $importer = app(WordPressOrderImporter::class);

        $result = $importer->importHistoricalOrderSilently('wc-100', [
            'user_id' => $user->id,
            'total' => 120,
        ]);

        $this->assertTrue($result['created']);
        $this->assertInstanceOf(Order::class, $result['order']);
        $this->assertNotNull($result['order']->fulfilled_at);
        $this->assertStringContainsString('historical_import', (string) $result['order']->notes);

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
        Mail::assertNothingSent();

        $again = $importer->importHistoricalOrderSilently('wc-100', [
            'user_id' => $user->id,
            'total' => 120,
        ]);
        $this->assertFalse($again['created']);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_historical_order_does_not_call_bosta_or_payment_gateway(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);

        $bosta = Mockery::mock(BostaClientInterface::class);
        $bosta->shouldNotReceive('createShipment');
        $this->app->instance(BostaClientInterface::class, $bosta);

        $user = User::factory()->create();
        app(WordPressOrderImporter::class)->importHistoricalOrderSilently('wc-bosta-safe', [
            'user_id' => $user->id,
            'total' => 50,
            'status' => 'delivered',
        ]);

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
        $this->assertDatabaseHas('orders', [
            'order_number' => 'WP-wc-bosta-safe',
            'requires_delivery_fulfillment' => 0,
        ]);
    }

    public function test_order_dry_run_silent_helper_writes_nothing(): void
    {
        $beforeOrders = Order::query()->count();
        $beforeMaps = LegacyImportMap::query()->count();

        $result = app(WordPressOrderImporter::class)->importHistoricalOrderSilently('wc-dry', [
            'user_id' => 1,
            'total' => 10,
        ], true);

        $this->assertFalse($result['created']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame($beforeOrders, Order::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
    }

    public function test_product_importer_reports_missing_and_writes_nothing_when_blocked(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $beforeMaps = LegacyImportMap::query()->count();
        $result = app(WordPressProductImporter::class)->import(true);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['wrote_to_database']);
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
    }

    public function test_course_real_import_stays_blocked_without_writing(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $beforeMaps = LegacyImportMap::query()->count();
        $result = app(WordPressCourseImporter::class)->import(false);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['wrote_to_database']);
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
    }

    public function test_missing_media_does_not_crash_product_inventory_when_blocked(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $result = app(WordPressProductImporter::class)->import(true);
        $this->assertArrayHasKey('wrote_to_database', $result);
        $this->assertFalse($result['wrote_to_database']);
    }

    public function test_malformed_order_status_falls_back_safely(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        $user = User::factory()->create();

        $result = app(WordPressOrderImporter::class)->importHistoricalOrderSilently('wc-bad-status', [
            'user_id' => $user->id,
            'total' => 1,
            'status' => 'wc-unknown-status-xyz',
        ]);

        // Unknown WC statuses map to Paid fallback inside importer; create still succeeds silently.
        $this->assertTrue($result['created']);
        $this->assertNotNull($result['order']);
        Event::assertNotDispatched(OrderPaid::class);
    }

    public function test_audit_reports_imported_map_counts(): void
    {
        LegacyImportMap::query()->create([
            'source' => 'wordpress',
            'entity_type' => 'user',
            'legacy_id' => '10',
            'local_id' => null,
            'legacy_email' => 'a@example.com',
        ]);
        LegacyImportMap::query()->create([
            'source' => 'wordpress',
            'entity_type' => 'user',
            'legacy_id' => '11',
            'legacy_email' => 'a@example.com',
        ]);

        $report = app(WordPressAuditService::class)->audit();

        $this->assertSame(2, $report['map_counts']['user']);
        $this->assertCount(1, $report['duplicate_emails']);

        $path = storage_path('framework/testing/wp-audit.json');
        $this->artisan('migration:wordpress:audit', ['--json' => $path])->assertSuccessful();
        $this->assertFileExists($path);
    }

    public function test_migration_run_stamps_maps_and_rollback_dry_run_is_traceable(): void
    {
        ActiveMigrationRun::clear();
        $runs = app(MigrationRunService::class);
        $run = $runs->start('testing', ['note' => 'traceability']);

        $user = User::factory()->create();
        $map = app(LegacyImportMapRepository::class)->upsertMapping('order', 'trace-1', [
            'local_id' => 999001,
            'metadata' => ['sim' => true],
        ]);

        $this->assertSame($run->id, $map->migration_run_id);
        $this->assertSame($run->id, ActiveMigrationRun::id());

        $plan = $runs->rollback($run, true);
        $this->assertSame('dry_run', $plan['status']);
        $this->assertFalse($plan['wrote_to_database']);
        $this->assertSame(1, $plan['map_rows']);
        $this->assertContains(999001, $plan['would_delete_local_ids']['order'] ?? []);

        // Cleanup without real rollback delete of nonexistent order row.
        LegacyImportMap::query()->where('legacy_id', 'trace-1')->delete();
        LegacyMigrationRun::query()->whereKey($run->id)->delete();
        ActiveMigrationRun::clear();
        $this->assertDatabaseMissing('orders', ['id' => 999001]);
        unset($user);
    }

    public function test_dependency_order_documented_courses_before_products(): void
    {
        $plan = file_get_contents(base_path('../docs/WORDPRESS-STAGING-IMPORT-PLAN.md'));
        $this->assertIsString($plan);
        $this->assertStringContainsString('Do NOT put Products before Courses', $plan);
        $coursesPos = strpos($plan, 'php artisan migration:wordpress:courses');
        $productsPos = strpos($plan, 'php artisan migration:wordpress:products');
        $this->assertNotFalse($coursesPos);
        $this->assertNotFalse($productsPos);
        $this->assertLessThan($productsPos, $coursesPos);
    }

    public function test_course_tree_staging_approved_and_orphans_excluded_in_config(): void
    {
        $this->assertTrue((bool) config('wordpress.course_tree.staging_approved'));
        $this->assertTrue((bool) config('wordpress.course_tree.exclude_orphans'));
        $this->assertSame([38266], config('wordpress.course_tree.fallback_course_ids'));
        $this->assertSame([38568], config('wordpress.course_tree.empty_source_course_ids'));
        $this->assertSame([6912], config('wordpress.skip_variable_product_ids'));
        $this->assertCount(10, config('wordpress.approved_map_existing.products'));
        $this->assertCount(1, config('wordpress.approved_map_existing.competitions'));
        $this->assertCount(1, config('wordpress.approved_map_existing.courses'));
        $this->assertSame('8507', (string) config('wordpress.approved_map_existing.courses.0.legacy_id'));
        $this->assertSame('ABSORB_TREE', config('wordpress.approved_map_existing.courses.0.tree_policy'));
        $this->assertSame('ABSORB_TREE', config('wordpress.course_tree_policies.8507'));
        $this->assertSame(
            'COURSE_8507_MAP_EXISTING_AND_IMPORT_CONTENT_HIGH_CONFIDENCE',
            config('wordpress.course_8507_verdict'),
        );
    }

    public function test_orders_dry_run_considers_all_historical_and_guest_bucket_when_connected(): void
    {
        $inspect = app(WordPressConnectionService::class)->inspect();
        if (($inspect['status'] ?? null) !== 'connected') {
            $this->markTestSkipped('wordpress_legacy not connected in test env');
        }

        $beforeOrders = Order::query()->count();
        $beforeMaps = LegacyImportMap::query()->count();
        $beforeRuns = LegacyMigrationRun::query()->count();

        $result = app(WordPressOrderImporter::class)->import(true);

        $this->assertFalse($result['wrote_to_database']);
        $this->assertSame(2573, $result['scanned']);
        $this->assertSame(2573, $result['historical_orders_considered']);
        $this->assertSame(1777, $result['would_create_guest_null_user']);
        $this->assertSame($beforeOrders, Order::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
        $this->assertSame($beforeRuns, LegacyMigrationRun::query()->count());
    }
}
