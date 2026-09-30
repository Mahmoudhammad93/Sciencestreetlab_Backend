<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressCompetitionSlotAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressOrderImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressVariableProductAnalyzer;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class WordPressMigrationPreStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_deterministic_wp_sku_and_genuine_sku_preserved(): void
    {
        $importer = app(WordPressProductImporter::class);
        $this->assertSame('WP-6811', $importer->resolveSku(null, '6811'));
        $this->assertSame('WP-6811', $importer->resolveSku('', '6811'));
        $this->assertSame('REAL-SKU', $importer->resolveSku('REAL-SKU', '6811'));
        $this->assertSame('REAL-SKU', $importer->resolveSku('  REAL-SKU  ', '6811'));
    }

    public function test_migration_run_rollback_dry_run_does_not_delete(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing', ['phase' => 'unit']);
        $this->assertSame('running', $run->status);

        $repo = app(LegacyImportMapRepository::class);
        $repo->upsertMapping('product', '999', [
            'local_id' => 12345,
            'migration_run_id' => $run->id,
        ]);

        $plan = $service->rollback($run, true);
        $this->assertSame('dry_run', $plan['status']);
        $this->assertFalse($plan['wrote_to_database']);
        $this->assertSame(1, LegacyImportMap::query()->where('migration_run_id', $run->id)->count());
        $this->assertNull(LegacyMigrationRun::query()->find($run->id)?->rolled_back_at);
    }

    public function test_map_idempotency_with_migration_run_id(): void
    {
        $run = app(MigrationRunService::class)->start('testing');
        $repo = app(LegacyImportMapRepository::class);
        $a = $repo->upsertMapping('course', '38568', [
            'local_id' => 7,
            'migration_run_id' => $run->id,
            'metadata' => ['flags' => ['EMPTY_SOURCE_COURSE']],
        ]);
        $b = $repo->upsertMapping('course', '38568', [
            'local_id' => 7,
            'migration_run_id' => $run->id,
        ]);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '38568')->count());
        $this->assertSame(['EMPTY_SOURCE_COURSE'], $a->metadata['flags'] ?? []);
    }

    public function test_user_email_collision_policy_maps_without_duplicate(): void
    {
        $existing = User::factory()->create(['email' => 'collision@example.com', 'name' => 'Existing']);
        $repo = app(LegacyImportMapRepository::class);
        $map = $repo->upsertMapping('user', '42', [
            'local_id' => $existing->id,
            'legacy_email' => 'collision@example.com',
            'metadata' => ['collision' => 'SAFE_MATCH', 'password_untouched' => true],
        ]);

        $this->assertSame($existing->id, $map->local_id);
        $this->assertSame(1, User::query()->where('email', 'collision@example.com')->count());
        $this->assertSame('Existing', $existing->fresh()->name);
    }

    public function test_historical_order_still_suppresses_side_effects(): void
    {
        Event::fake([OrderPaid::class, OrderFulfilled::class]);
        $user = User::factory()->create();
        app(WordPressOrderImporter::class)->importHistoricalOrderSilently('wc-prestage', [
            'user_id' => $user->id,
            'total' => 10,
        ]);
        Event::assertNotDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderFulfilled::class);
    }

    public function test_prefix_guards_for_analyzers(): void
    {
        config(['wordpress.prefix' => 'wp_3_']);
        $this->expectException(\RuntimeException::class);
        app(WordPressVariableProductAnalyzer::class)->analyze(6912);
    }

    public function test_course_tree_and_slot_analyzers_refuse_orphan_prefix(): void
    {
        config(['wordpress.prefix' => 'wp_2_']);
        $this->expectException(\RuntimeException::class);
        app(WordPressCompetitionSlotAnalyzer::class)->analyze();
    }

    public function test_production_prefix_ok_for_connection(): void
    {
        config(['wordpress.prefix' => 'wp_']);
        app(WordPressConnectionService::class)->assertProductionPrefix();
        $this->assertTrue(true);
    }

    public function test_dry_run_commands_write_nothing_when_unconfigured(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $before = LegacyImportMap::query()->count();
        $this->artisan('migration:wordpress:products', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('migration:wordpress:competition', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, LegacyImportMap::query()->count());
    }
}
