<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressOrderImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class WordPressOrderTimestampRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ActiveMigrationRun::clear();
        putenv('WORDPRESS_REAL_PERSIST=1');
        $_ENV['WORDPRESS_REAL_PERSIST'] = '1';
        $_SERVER['WORDPRESS_REAL_PERSIST'] = '1';
    }

    protected function tearDown(): void
    {
        ActiveMigrationRun::clear();
        putenv('WORDPRESS_REAL_PERSIST');
        unset($_ENV['WORDPRESS_REAL_PERSIST'], $_SERVER['WORDPRESS_REAL_PERSIST']);
        parent::tearDown();
    }

    public function test_normalize_gmt_parses_wp_52009_value_as_utc(): void
    {
        $importer = app(WordPressOrderImporter::class);
        $normalized = $importer->normalizeGmtDateTime('2026-04-24 00:08:47');

        $this->assertInstanceOf(CarbonImmutable::class, $normalized);
        $this->assertSame('UTC', $normalized->timezoneName);
        $this->assertSame('2026-04-24 00:08:47', $normalized->format('Y-m-d H:i:s'));
    }

    public function test_historical_import_accepts_cairo_dst_gap_gmt_string_as_utc_paid_at(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        Mail::fake();

        $run = app(MigrationRunService::class)->start('testing', ['note' => 'cp6a']);
        ActiveMigrationRun::set($run);

        $importer = app(WordPressOrderImporter::class);
        $result = $importer->importHistoricalOrderSilently('52009', [
            'user_id' => null,
            'is_guest' => true,
            'wp_customer_id' => 0,
            'billing_email' => 'gap@example.com',
            'total' => 3486,
            'status' => 'paid',
            'paid_at' => '2026-04-24 00:08:47',
            'fulfilled_at' => null,
            'paid_at_source' => 'date_created_gmt',
        ]);

        $this->assertTrue($result['created']);
        $order = $result['order'];
        $this->assertNotNull($order);
        $this->assertNull($order->user_id);
        $this->assertFalse($order->requires_delivery_fulfillment);
        $this->assertSame('2026-04-24 00:08:47', $order->paid_at?->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertNull($order->fulfilled_at);
        $this->assertSame('date_created_gmt', $result['map']->metadata['paid_at_source'] ?? null);

        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
        Mail::assertNothingSent();
    }

    public function test_registered_order_uses_user_map_and_skips_duplicate_on_rerun(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        $user = User::factory()->create();
        $run = app(MigrationRunService::class)->start('testing', ['note' => 'cp6a-reg']);
        ActiveMigrationRun::set($run);

        $importer = app(WordPressOrderImporter::class);
        $first = $importer->importHistoricalOrderSilently('wc-reg-1', [
            'user_id' => $user->id,
            'is_guest' => false,
            'wp_customer_id' => 42,
            'total' => 50,
            'paid_at' => '2026-04-24 00:08:47',
            'fulfilled_at' => null,
        ]);
        $second = $importer->importHistoricalOrderSilently('wc-reg-1', [
            'user_id' => $user->id,
            'total' => 50,
            'paid_at' => '2026-04-24 00:08:47',
        ]);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($user->id, $first['order']->user_id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, LegacyImportMap::query()->where('entity_type', 'order')->where('legacy_id', 'wc-reg-1')->count());
        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
    }

    public function test_ensure_utc_session_timezone_is_idempotent_and_safe(): void
    {
        $importer = app(WordPressOrderImporter::class);
        $importer->ensureUtcSessionTimezone();
        $importer->ensureUtcSessionTimezone();

        if (DB::connection()->getDriverName() === 'mysql') {
            $tz = DB::select('select @@session.time_zone as tz')[0]->tz ?? null;
            $this->assertSame('+00:00', $tz);
        } else {
            $this->assertTrue(true);
        }
    }
}
