<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Migration\Application\Services\WordPress\MigrationImportOutcome;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressApprovedCollisionMapper;
use App\Modules\Migration\Application\Services\WordPress\WordPressCollisionAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Final collision approval preparation: approved MAP_EXISTING registry + safety.
 */
final class WordPressApprovedCollisionMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_approves_ten_simple_products_competition_and_course_8507(): void
    {
        $products = config('wordpress.approved_map_existing.products');
        $this->assertCount(10, $products);
        $ids = array_map(fn ($row) => (string) $row['legacy_id'], $products);
        $this->assertSame([
            '6811', '6855', '6867', '6874', '6876', '6878', '6886', '6887', '6890', '6910',
        ], $ids);
        $this->assertNotContains('6912', $ids);
        $this->assertSame([6912], config('wordpress.skip_variable_product_ids'));

        $comps = config('wordpress.approved_map_existing.competitions');
        $this->assertCount(1, $comps);
        $this->assertSame('4', (string) $comps[0]['legacy_id']);
        $this->assertSame('microscope-100-challenge', $comps[0]['local_slug']);

        $courses = config('wordpress.approved_map_existing.courses');
        $this->assertCount(1, $courses);
        $this->assertSame('8507', (string) $courses[0]['legacy_id']);
        $this->assertSame('microscope-course', $courses[0]['local_slug']);
        $this->assertSame('MAP_EXISTING', $courses[0]['decision']);
        $this->assertSame('ABSORB_TREE', $courses[0]['tree_policy']);
        $this->assertSame('ABSORB_TREE', config('wordpress.course_tree_policies.8507'));
        $this->assertSame(
            'COURSE_8507_MAP_EXISTING_AND_IMPORT_CONTENT_HIGH_CONFIDENCE',
            config('wordpress.course_8507_verdict'),
        );
    }

    public function test_simulate_dry_run_does_not_write_maps(): void
    {
        $this->seedApprovedTargets();

        $beforeMaps = LegacyImportMap::query()->count();
        $beforeProducts = Product::query()->count();
        $beforeCompetitions = Competition::query()->count();
        $beforeCourses = Course::query()->count();

        $result = app(WordPressApprovedCollisionMapper::class)->simulate();

        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['wrote_to_database']);
        $this->assertSame(10, $result['products']['would_map_existing']);
        $this->assertSame(1, $result['competitions']['would_map_existing']);
        $this->assertSame(1, $result['courses']['approved_count']);
        $this->assertSame(1, $result['courses']['would_map_existing']);
        $this->assertSame(
            'COURSE_8507_MAP_EXISTING_AND_IMPORT_CONTENT_HIGH_CONFIDENCE',
            $result['course_8507']['verdict'],
        );
        $this->assertTrue($result['course_8507']['approved_for_map_existing']);
        $this->assertSame('ABSORB_TREE', $result['course_8507']['tree_policy']);
        $this->assertSame(
            WordPressApprovedCollisionMapper::STATUS_SKIPPED_VARIABLE,
            $result['skipped_variable_products'][0]['status'],
        );

        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
        $this->assertSame($beforeProducts, Product::query()->count());
        $this->assertSame($beforeCompetitions, Competition::query()->count());
        $this->assertSame($beforeCourses, Course::query()->count());
        $this->assertSame(
            520.0,
            (float) Product::query()->where('slug', 'hydraulic-excavator-arm')->value('price'),
        );
    }

    public function test_apply_maps_only_with_mapped_to_existing_ownership(): void
    {
        $run = app(MigrationRunService::class)->start('testing-approved-collisions');
        $this->seedApprovedTargets();

        $arm = Product::query()->where('slug', 'hydraulic-excavator-arm')->firstOrFail();
        $armTitle = $arm->getTranslation('name', 'ar');
        $armPrice = (float) $arm->price;
        $competition = Competition::query()->where('slug', 'microscope-100-challenge')->firstOrFail();
        $compTitle = $competition->getTranslation('title', 'ar');
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();
        $courseTitle = $course->getTranslation('title', 'ar');

        $result = app(WordPressApprovedCollisionMapper::class)->apply();

        $this->assertFalse($result['dry_run']);
        $this->assertTrue($result['wrote_to_database']);
        $this->assertSame(10, $result['products']['mapped']);
        $this->assertSame(1, $result['competitions']['mapped']);
        $this->assertSame(1, $result['courses']['mapped']);

        $productMap = LegacyImportMap::query()
            ->where('entity_type', 'product')
            ->where('legacy_id', '6811')
            ->first();
        $this->assertNotNull($productMap);
        $this->assertSame($arm->id, $productMap->local_id);
        $this->assertSame($run->id, $productMap->migration_run_id);
        $this->assertTrue($productMap->wasMappedToExisting());
        $this->assertFalse($productMap->wasCreatedByMigration());
        $this->assertSame('MAP_EXISTING', $productMap->metadata['collision_decision']);
        $this->assertFalse($productMap->metadata['overwrite']);

        $compMap = LegacyImportMap::query()
            ->where('entity_type', 'competition')
            ->where('legacy_id', '4')
            ->first();
        $this->assertNotNull($compMap);
        $this->assertSame($competition->id, $compMap->local_id);
        $this->assertTrue($compMap->wasMappedToExisting());
        $this->assertFalse($compMap->wasCreatedByMigration());

        $courseMap = LegacyImportMap::query()
            ->where('entity_type', 'course')
            ->where('legacy_id', '8507')
            ->first();
        $this->assertNotNull($courseMap);
        $this->assertSame($course->id, $courseMap->local_id);
        $this->assertTrue($courseMap->wasMappedToExisting());
        $this->assertFalse($courseMap->wasCreatedByMigration());
        $this->assertSame('ABSORB_TREE', $courseMap->metadata['tree_policy']);
        $this->assertSame($run->id, $courseMap->migration_run_id);

        $freshArm = $arm->fresh();
        $this->assertSame($armTitle, $freshArm->getTranslation('name', 'ar'));
        $this->assertSame($armPrice, (float) $freshArm->price);
        $this->assertSame($compTitle, $competition->fresh()->getTranslation('title', 'ar'));
        $this->assertSame($courseTitle, $course->fresh()->getTranslation('title', 'ar'));
        $this->assertSame(10, Product::query()->count());
        $this->assertSame(1, Competition::query()->count());
        $this->assertSame(1, Course::query()->count());
    }

    public function test_rollback_after_approved_maps_never_deletes_existing_rows(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-approved-rollback');
        $this->seedApprovedTargets();

        app(WordPressApprovedCollisionMapper::class)->apply();

        $arm = Product::query()->where('slug', 'hydraulic-excavator-arm')->firstOrFail();
        $competition = Competition::query()->where('slug', 'microscope-100-challenge')->firstOrFail();
        $course = Course::query()->where('slug', 'microscope-course')->firstOrFail();

        $plan = $service->rollback($run, true);
        $this->assertContains($arm->id, $plan['would_unmap_only_local_ids']['product'] ?? []);
        $this->assertContains($competition->id, $plan['would_unmap_only_local_ids']['competition'] ?? []);
        $this->assertContains($course->id, $plan['would_unmap_only_local_ids']['course'] ?? []);
        $this->assertArrayNotHasKey('product', $plan['would_delete_local_ids']);
        $this->assertArrayNotHasKey('competition', $plan['would_delete_local_ids']);
        $this->assertArrayNotHasKey('course', $plan['would_delete_local_ids']);

        $service->rollback($run, false);

        $this->assertNotNull($arm->fresh());
        $this->assertNull($arm->fresh()->deleted_at);
        $this->assertSame('hydraulic-excavator-arm', $arm->fresh()->slug);
        $this->assertNotNull(Competition::query()->find($competition->id));
        $this->assertSame('microscope-100-challenge', $competition->fresh()->slug);
        $this->assertNull($course->fresh()->deleted_at);
        $this->assertSame('microscope-course', $course->fresh()->slug);
        $this->assertSame(0, LegacyImportMap::query()->where('migration_run_id', $run->id)->count());
    }

    public function test_product_importer_uses_approved_decision_for_slug_collision(): void
    {
        Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'sku' => 'SS-WP-6811',
            'slug' => 'hydraulic-excavator-arm',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 520,
            'currency' => 'EGP',
            'name' => ['ar' => 'الحفار الهيدروليكي'],
            'published_at' => now(),
        ]);

        $outcome = MigrationImportOutcome::classifyProductShell(
            false,
            true,
            false,
            app(WordPressApprovedCollisionMapper::class)->productDecision('6811'),
        );
        $this->assertSame(MigrationImportOutcome::WOULD_MAP_EXISTING, $outcome);

        $run = app(MigrationRunService::class)->start('testing-persist-approved');
        $status = app(WordPressProductImporter::class)->persistProduct([
            'legacy_id' => '6811',
            'slug' => 'hydraulic-excavator-arm',
            'proposed_laravel_type' => ProductType::Kit->value,
            'name' => ['ar' => 'WP must not overwrite'],
            'wc_type' => 'simple',
            'price' => 1,
        ], 'WP-6811');

        $this->assertSame('skipped_mapped', $status);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(
            'الحفار الهيدروليكي',
            Product::query()->first()->getTranslation('name', 'ar'),
        );
        $this->assertSame(520.0, (float) Product::query()->first()->price);

        $map = LegacyImportMap::query()->where('entity_type', 'product')->where('legacy_id', '6811')->first();
        $this->assertTrue($map->wasMappedToExisting());
        $this->assertFalse($map->wasCreatedByMigration());
        $this->assertSame($run->id, $map->migration_run_id);

        $analyzer = app(WordPressCollisionAnalyzer::class);
        $this->assertSame(
            'SKIPPED_MAPPED',
            $analyzer->classifyProductCollision('6811', 'hydraulic-excavator-arm', true, false),
        );
    }

    public function test_variable_6912_remains_skipped_and_not_approved(): void
    {
        $mapper = app(WordPressApprovedCollisionMapper::class);
        $this->assertNull($mapper->productDecision('6912'));
        $this->assertContains(6912, $mapper->skippedVariableProductIds());

        $outcome = MigrationImportOutcome::classifyProductShell(false, true, true, null);
        $this->assertSame(MigrationImportOutcome::WOULD_SKIP, $outcome);
    }

    /**
     * @return void
     */
    private function seedApprovedTargets(): void
    {
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'كورس الميكروسكوب', 'en' => 'Microscope Course'],
        ]);

        Competition::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-100-challenge',
            'prerequisite_course_id' => $course->id,
            'required_photos' => 100,
            'photos_per_sample' => 2,
            'max_photos_per_sample' => 2,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(6),
            'status' => 'active',
            'title' => ['ar' => 'تحدي ال100 صورة', 'en' => '100 Photo Challenge'],
        ]);

        $kits = [
            '6811' => ['slug' => 'hydraulic-excavator-arm', 'name' => 'الحفار الهيدروليكي', 'price' => 520],
            '6855' => ['slug' => 'reptile-robot', 'name' => 'روبوت الزواحف', 'price' => 573],
            '6867' => ['slug' => 'race-car', 'name' => 'سيارة السباق', 'price' => 650],
            '6874' => ['slug' => 'rowing-boat', 'name' => 'قارب التجديف', 'price' => 650],
            '6876' => ['slug' => 'brunei-volleyball', 'name' => 'كرة برونللي الطايرة', 'price' => 573],
            '6878' => ['slug' => 'space-engine', 'name' => 'محرك الفضاء', 'price' => 573],
            '6886' => ['slug' => 'spinbot', 'name' => 'روبوت الرسم الدوار', 'price' => 653],
            '6887' => ['slug' => 'ultrasonic-obstacle-car', 'name' => 'السيارة الذكية', 'price' => 653],
            '6890' => ['slug' => 'brachiosaurus-the-adventurous', 'name' => 'البراكيوصورس المغامر', 'price' => 653],
            '6910' => ['slug' => 'manual-power-generator', 'name' => 'مولد الطاقة اليدوي', 'price' => 520],
        ];

        foreach ($kits as $legacyId => $kit) {
            Product::query()->create([
                'uuid' => (string) Str::uuid(),
                'sku' => 'SS-WP-'.$legacyId,
                'slug' => $kit['slug'],
                'type' => ProductType::Kit,
                'status' => ProductStatus::Published,
                'price' => $kit['price'],
                'currency' => 'EGP',
                'name' => ['ar' => $kit['name'], 'en' => $kit['name']],
                'published_at' => now(),
            ]);
        }
    }
}
