<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\WordPressAuditService;
use App\Modules\Migration\Application\Services\WordPress\WordPressOrderImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressUserImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class WordPressMigrationFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_command_reports_blocked_without_credentials(): void
    {
        $this->artisan('migration:wordpress:inspect')
            ->expectsOutputToContain('BLOCKED_UNTIL_WORDPRESS_DB_DUMP')
            ->assertSuccessful();
    }

    public function test_users_dry_run_does_not_create_users_when_blocked(): void
    {
        $before = User::query()->count();

        $this->artisan('migration:wordpress:users', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($before, User::query()->count());
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

    public function test_map_upsert_is_idempotent(): void
    {
        $repo = app(LegacyImportMapRepository::class);
        $first = $repo->upsertMapping('course', '99', ['local_id' => 1]);
        $second = $repo->upsertMapping('course', '99', ['local_id' => 1]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '99')->count());
    }

    public function test_historical_order_import_does_not_dispatch_fulfillment_events(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);

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

        $again = $importer->importHistoricalOrderSilently('wc-100', [
            'user_id' => $user->id,
            'total' => 120,
        ]);
        $this->assertFalse($again['created']);
        $this->assertSame(1, Order::query()->count());
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
    }
}
