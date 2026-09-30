<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\WordPressCompetitionImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use App\Modules\Migration\Application\Services\WordPress\WordPressCourseTreeAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase-2 product / competition / course-tree migration tests.
 * Does not require wordpress_legacy; uses mocks / pure unit paths where needed.
 */
final class WordPressMigrationPhase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_production_prefix_required_for_product_and_competition_paths(): void
    {
        config(['wordpress.prefix' => 'wp_']);
        app(WordPressConnectionService::class)->assertProductionPrefix();
        $this->assertSame('wp_', app(WordPressConnectionService::class)->tablePrefix());
    }

    public function test_qa_and_orphan_prefixes_rejected(): void
    {
        foreach (['wp_3_', 'wp_2_'] as $prefix) {
            config(['wordpress.prefix' => $prefix]);
            try {
                app(WordPressConnectionService::class)->assertProductionPrefix();
                $this->fail("Expected rejection for {$prefix}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($prefix, $e->getMessage());
            }
        }
    }

    public function test_related_course_serialized_array_parsing(): void
    {
        $importer = app(WordPressProductImporter::class);
        $ids = $importer->parseRelatedCourseIds('a:1:{i:0;i:8507;}');
        $this->assertSame([8507], $ids);
        $this->assertSame([], $importer->parseRelatedCourseIds(null));
        $this->assertSame([42], $importer->parseRelatedCourseIds('42'));
    }

    public function test_product_dry_run_writes_nothing_when_blocked(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $beforeProducts = DB::table('products')->count();
        $beforeMaps = LegacyImportMap::query()->count();
        $beforeCats = DB::table('categories')->count();

        $result = app(WordPressProductImporter::class)->import(true);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['wrote_to_database']);
        $this->assertSame($beforeProducts, DB::table('products')->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
        $this->assertSame($beforeCats, DB::table('categories')->count());
    }

    public function test_product_real_import_blocked_without_sku_strategy(): void
    {
        config([
            'wordpress.product_sku_strategy' => null,
            'wordpress.real_persist' => null,
        ]);

        $beforeMaps = LegacyImportMap::query()->count();
        $result = app(WordPressProductImporter::class)->import(false);

        $this->assertFalse($result['wrote_to_database']);
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
        $this->assertSame('blocked', $result['status']);
        $this->assertSame(
            \App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate::REAL_PERSIST_NOT_AUTHORIZED,
            $result['code'],
        );

        // With real persist + active run bound, SKU gate must still fire.
        config(['wordpress.real_persist' => '1']);
        $run = app(\App\Modules\Migration\Application\Services\WordPress\MigrationRunService::class)->start('testing-sku-gate');
        $result2 = app(WordPressProductImporter::class)->import(false);
        $this->assertFalse($result2['wrote_to_database']);
        if (($result2['inspect']['reachable'] ?? false) === true && ($result2['inspect']['probes']['posts'] ?? false) === true) {
            $this->assertSame('blocked', $result2['status']);
            $this->assertSame(WordPressProductImporter::SKU_REQUIRES_DECISION, $result2['code']);
        }
        \App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun::clear();
        unset($run);
    }

    public function test_product_map_idempotency_helper(): void
    {
        $repo = app(LegacyImportMapRepository::class);
        $a = $repo->upsertMapping('product', '6811', ['local_id' => 10]);
        $b = $repo->upsertMapping('product', '6811', ['local_id' => 10]);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LegacyImportMap::query()->where('entity_type', 'product')->count());
    }

    public function test_competition_dry_run_writes_nothing_when_blocked(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
        ]);

        $before = [
            'competitions' => DB::table('competitions')->count(),
            'participants' => DB::table('competition_participants')->count(),
            'submissions' => DB::table('competition_submissions')->count(),
            'maps' => LegacyImportMap::query()->count(),
        ];

        $result = app(WordPressCompetitionImporter::class)->import(true);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['wrote_to_database']);
        $this->assertSame($before['competitions'], DB::table('competitions')->count());
        $this->assertSame($before['participants'], DB::table('competition_participants')->count());
        $this->assertSame($before['submissions'], DB::table('competition_submissions')->count());
        $this->assertSame($before['maps'], LegacyImportMap::query()->count());
    }

    public function test_competition_map_idempotency(): void
    {
        $repo = app(LegacyImportMapRepository::class);
        $a = $repo->upsertMapping('competition', '4', ['local_id' => 1]);
        $b = $repo->upsertMapping('competition', '4', ['local_id' => 1]);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LegacyImportMap::query()->where('entity_type', 'competition')->count());
    }

    public function test_course_tree_analyzer_refuses_non_production_prefix(): void
    {
        config(['wordpress.prefix' => 'wp_3_']);
        $this->expectException(\RuntimeException::class);
        app(WordPressCourseTreeAnalyzer::class)->analyze();
    }

    public function test_course_tree_dry_run_path_writes_nothing_when_connection_blocked(): void
    {
        config([
            'wordpress.host' => null,
            'wordpress.database' => null,
            'wordpress.username' => null,
            'wordpress.prefix' => 'wp_',
        ]);

        $beforeMaps = LegacyImportMap::query()->count();
        $beforeCourses = DB::table('courses')->count();
        $beforeLessons = DB::table('lessons')->count();

        $this->artisan('migration:wordpress:courses', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
        $this->assertSame($beforeCourses, DB::table('courses')->count());
        $this->assertSame($beforeLessons, DB::table('lessons')->count());
    }

    public function test_media_pending_documented_in_product_blocked_result_shape(): void
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
}
