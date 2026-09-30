<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationImportOutcome;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressApprovedCollisionMapper;
use App\Modules\Migration\Application\Services\WordPress\WordPressCompetitionImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressCourseImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Part F/G/H: ownership-aware rollback, shared dry-run outcomes, course map safety.
 */
final class WordPressMigrationOwnershipAndCourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_outcome_classifier_shared_vocabulary(): void
    {
        $this->assertSame(
            MigrationImportOutcome::WOULD_CREATE,
            MigrationImportOutcome::classifyCourseShell(false, false),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_SKIP,
            MigrationImportOutcome::classifyCourseShell(true, true),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_FAIL,
            MigrationImportOutcome::classifyCourseShell(false, true),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_MAP_EXISTING,
            MigrationImportOutcome::classifyCourseShell(false, true, 'MAP_EXISTING'),
        );
        // Companion map (WP slug ≠ local slug): approved MAP_EXISTING still predicts map.
        $this->assertSame(
            MigrationImportOutcome::WOULD_MAP_EXISTING,
            MigrationImportOutcome::classifyCourseShell(false, false, 'MAP_EXISTING'),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_SKIP,
            MigrationImportOutcome::classifyProductShell(false, false, true),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_FAIL,
            MigrationImportOutcome::classifyProductShell(false, true, false),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_MAP_EXISTING,
            MigrationImportOutcome::classifyCompetitionShell(false, true, 'MAP_EXISTING', true),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_DEFER,
            MigrationImportOutcome::classifyCompetitionShell(false, false, null, false),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_DEFER,
            MigrationImportOutcome::classifyMappedDependent(false, false),
        );
        $this->assertSame(
            MigrationImportOutcome::WOULD_CREATE,
            MigrationImportOutcome::classifyMappedDependent(false, true),
        );
    }

    public function test_course_slug_collision_refuses_create(): void
    {
        Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Local microscope'],
        ]);

        $result = app(WordPressCourseImporter::class)->persistCourseTree([
            'legacy_id' => '8507',
            'title' => 'كورس الميكروسكوب',
            'slug' => 'microscope-course',
            'content' => 'wp',
            'status' => 'publish',
            'flags' => [],
            'lessons' => [],
        ]);

        $this->assertFalse($result['course_created']);
        $this->assertSame('MERGE_REQUIRES_DECISION', $result['code']);
        $this->assertSame(1, Course::query()->count());
        $this->assertNull(
            LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '8507')->first()
        );
    }

    public function test_map_existing_course_stamps_mapped_ownership(): void
    {
        $run = app(MigrationRunService::class)->start('testing-map-course');
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Local'],
        ]);

        app(WordPressCourseImporter::class)->mapExistingCourse('8507', $course->id, [
            'note' => 'TITLE_MATCH_SLUG_DIFFERS',
        ]);

        $map = LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '8507')->first();
        $this->assertNotNull($map);
        $this->assertSame($course->id, $map->local_id);
        $this->assertSame($run->id, $map->migration_run_id);
        $this->assertTrue($map->wasMappedToExisting());
        $this->assertFalse($map->wasCreatedByMigration());
        $this->assertSame('MAP_EXISTING', $map->metadata['collision_decision']);
        $this->assertSame('TITLE_MATCH_SLUG_DIFFERS', $map->metadata['note']);
        $this->assertSame('Local', $course->fresh()->getTranslation('title', 'ar'));
    }

    public function test_rollback_deletes_created_course_but_not_mapped_existing_product(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-rollback-ownership');

        $createdCourse = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'wp-created-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Created by migration'],
        ]);
        app(LegacyImportMapRepository::class)->upsertMapping('course', '99901', [
            'local_id' => $createdCourse->id,
            'migration_run_id' => $run->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'legacy_wordpress_id' => '99901',
            ]),
        ]);

        $preexisting = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'sku' => 'SS-WP-6811',
            'slug' => 'hydraulic-excavator-arm',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 520,
            'currency' => 'EGP',
            'name' => ['ar' => 'الحفار الهيدروليكي', 'en' => 'الحفار الهيدروليكي'],
            'published_at' => now(),
        ]);
        app(WordPressProductImporter::class)->mapExistingProduct('6811', $preexisting->id);

        $mappedProductMap = LegacyImportMap::query()
            ->where('entity_type', 'product')
            ->where('legacy_id', '6811')
            ->first();
        $this->assertTrue($mappedProductMap->wasMappedToExisting());
        $this->assertSame($run->id, $mappedProductMap->migration_run_id);

        $plan = $service->rollback($run, true);
        $this->assertSame('dry_run', $plan['status']);
        $this->assertContains($createdCourse->id, $plan['would_delete_local_ids']['course'] ?? []);
        $this->assertContains($preexisting->id, $plan['would_unmap_only_local_ids']['product'] ?? []);
        $this->assertArrayNotHasKey('product', $plan['would_delete_local_ids']);

        $result = $service->rollback($run, false);
        $this->assertSame('rolled_back', $result['status']);

        $this->assertNotNull($createdCourse->fresh()->deleted_at);
        $this->assertNull($preexisting->fresh()->deleted_at);
        $this->assertSame('hydraulic-excavator-arm', $preexisting->fresh()->slug);
        $this->assertSame(0, LegacyImportMap::query()->where('migration_run_id', $run->id)->count());
    }

    public function test_rollback_does_not_delete_mapped_existing_competition(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-rollback-competition');

        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Microscope'],
        ]);
        $competition = Competition::query()->create([
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

        app(LegacyImportMapRepository::class)->upsertMapping('competition', '4', [
            'local_id' => $competition->id,
            'migration_run_id' => $run->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                'collision_decision' => 'MAP_EXISTING',
                'overwrite' => false,
            ]),
        ]);

        $service->rollback($run, false);

        $this->assertNotNull(Competition::query()->find($competition->id));
        $this->assertSame('microscope-100-challenge', $competition->fresh()->slug);
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'competition')->count());
    }

    public function test_rollback_deletes_migration_created_submission_but_not_mapped_competition(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-rollback-submission');

        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Microscope'],
        ]);
        $competition = Competition::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-100-challenge',
            'prerequisite_course_id' => $course->id,
            'required_photos' => 100,
            'photos_per_sample' => 2,
            'max_photos_per_sample' => 2,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(6),
            'status' => 'active',
            'title' => ['ar' => 'تحدي ال100 صورة'],
        ]);

        $repo = app(LegacyImportMapRepository::class);
        $repo->upsertMapping('competition', '4', [
            'local_id' => $competition->id,
            'migration_run_id' => $run->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                'collision_decision' => 'MAP_EXISTING',
                'overwrite' => false,
            ]),
        ]);

        $user = User::factory()->create();
        $importer = app(WordPressCompetitionImporter::class);
        $participant = $importer->persistParticipant('31', $competition->id, $user->id, '99', 'active', now());
        $result = $importer->persistHistoricalSubmission(
            (object) [
                'id' => 501,
                'status' => 'approved',
                'sample_name' => 'specimen-a',
                'description' => 'd',
                'scientific_info' => 's',
                'created_at' => now(),
                'image_id' => 0,
                'reviewed_at' => null,
                'reviewed_by' => 0,
            ],
            $participant['id'],
            $competition->id,
            0,
        );
        $this->assertTrue($result['created']);
        $submissionId = $result['local_id'];

        $service->rollback($run, false);

        $this->assertNotNull(Competition::query()->find($competition->id));
        $this->assertSame('microscope-100-challenge', $competition->fresh()->slug);
        $this->assertNull(CompetitionSubmission::query()->find($submissionId));
        $this->assertSame(0, LegacyImportMap::query()->where('migration_run_id', $run->id)->count());
    }

    public function test_placeholder_null_local_id_map_may_upgrade_to_created_by_migration(): void
    {
        $run = app(MigrationRunService::class)->start('testing-placeholder-upgrade');
        $repo = app(LegacyImportMapRepository::class);

        // Former COMPETITION_SLOT_SCHEMA_BLOCKER metadata-only map.
        $repo->upsertMapping('competition_submission', '501', [
            'local_id' => null,
            'migration_run_id' => $run->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                'code' => 'COMPETITION_SLOT_SCHEMA_BLOCKER',
            ]),
        ]);

        $repo->upsertMapping('competition_submission', '501', [
            'local_id' => 999,
            'migration_run_id' => $run->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'code' => 'COMPETITION_SLOT_SCHEMA_RESOLVED',
            ]),
        ]);

        $map = LegacyImportMap::query()
            ->where('entity_type', 'competition_submission')
            ->where('legacy_id', '501')
            ->first();
        $this->assertSame(999, $map->local_id);
        $this->assertTrue($map->wasCreatedByMigration());
        $this->assertFalse($map->wasMappedToExisting());
    }

    public function test_created_product_is_eligible_for_rollback_delete(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-rollback-created-product');

        $ok = app(WordPressProductImporter::class)->persistProduct([
            'legacy_id' => '99950',
            'slug' => 'brand-new-rollback-kit',
            'proposed_laravel_type' => ProductType::Kit->value,
            'name' => ['ar' => 'New'],
            'wc_type' => 'simple',
            'price' => 100,
        ], 'WP-99950');
        $this->assertSame('created', $ok);

        $product = Product::query()->where('slug', 'brand-new-rollback-kit')->first();
        $map = LegacyImportMap::query()->where('entity_type', 'product')->where('legacy_id', '99950')->first();
        $this->assertTrue($map->wasCreatedByMigration());
        $this->assertSame($run->id, $map->migration_run_id);

        $service->rollback($run, false);

        $this->assertNotNull($product->fresh()->deleted_at);
        $this->assertSame(0, LegacyImportMap::query()->where('legacy_id', '99950')->count());
    }

    public function test_rerun_cannot_flip_mapped_existing_ownership_to_created(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-ownership-immutability');
        $repo = app(LegacyImportMapRepository::class);

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'sku' => 'SS-WP-6811',
            'slug' => 'hydraulic-excavator-arm',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 520,
            'currency' => 'EGP',
            'name' => ['ar' => 'الحفار الهيدروليكي', 'en' => 'Hydraulic'],
            'published_at' => now(),
        ]);
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'كورس الميكروسكوب'],
        ]);
        $competition = Competition::query()->create([
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

        app(WordPressProductImporter::class)->mapExistingProduct('6811', $product->id);
        app(WordPressCourseImporter::class)->mapExistingCourse('8507', $course->id, [
            'note' => 'TITLE_MATCH_SLUG_DIFFERS',
        ]);
        $repo->upsertMapping('competition', '4', [
            'local_id' => $competition->id,
            'migration_run_id' => $run->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting([
                'collision_decision' => 'MAP_EXISTING',
                'overwrite' => false,
            ]),
        ]);

        // Hostile / mistaken re-run: attempt to stamp created_by_migration on each map.
        foreach ([['product', '6811'], ['course', '8507'], ['competition', '4']] as [$type, $legacyId]) {
            $repo->upsertMapping($type, $legacyId, [
                'local_id' => match ($type) {
                    'product' => $product->id,
                    'course' => $course->id,
                    default => $competition->id,
                },
                'migration_run_id' => $run->id,
                'metadata' => LegacyImportMapRepository::ownershipCreated([
                    'hostile_rerun' => true,
                ]),
            ]);
            $map = LegacyImportMap::query()->where('entity_type', $type)->where('legacy_id', $legacyId)->first();
            $this->assertTrue($map->wasMappedToExisting(), "{$type} must stay mapped_to_existing");
            $this->assertFalse($map->wasCreatedByMigration(), "{$type} must not become created_by_migration");
            $this->assertTrue($map->metadata['hostile_rerun']);
        }

        $plan = $service->rollback($run, true);
        $this->assertArrayNotHasKey('product', $plan['would_delete_local_ids']);
        $this->assertArrayNotHasKey('course', $plan['would_delete_local_ids']);
        $this->assertArrayNotHasKey('competition', $plan['would_delete_local_ids']);

        $service->rollback($run, false);

        $this->assertNull($product->fresh()->deleted_at);
        $this->assertNull($course->fresh()->deleted_at);
        $this->assertNotNull(Competition::query()->find($competition->id));
        $this->assertSame('microscope-100-challenge', $competition->fresh()->slug);
        $this->assertSame(0, LegacyImportMap::query()->where('migration_run_id', $run->id)->count());
    }

    public function test_rollback_never_deletes_mapped_existing_course(): void
    {
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-rollback-mapped-course');

        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'كورس الميكروسكوب'],
        ]);
        app(WordPressCourseImporter::class)->mapExistingCourse('8507', $course->id);

        $service->rollback($run, false);

        $this->assertNull($course->fresh()->deleted_at);
        $this->assertSame('microscope-course', $course->fresh()->slug);
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '8507')->count());
    }

    public function test_course_8507_absorb_tree_imports_wp_lessons_preserves_native_and_rollbacks_only_imported(): void
    {
        $this->assertSame(
            'COURSE_8507_MAP_EXISTING_AND_IMPORT_CONTENT_HIGH_CONFIDENCE',
            config('wordpress.course_8507_verdict'),
        );
        $this->assertSame('ABSORB_TREE', config('wordpress.course_tree_policies.8507'));
        $this->assertSame(
            'MAP_EXISTING',
            app(WordPressApprovedCollisionMapper::class)->courseDecision('8507'),
        );

        $run = app(MigrationRunService::class)->start('testing-8507-absorb-tree');
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'كورس الميكروسكوب'],
        ]);

        $nativeLesson = \App\Modules\Learning\Infrastructure\Persistence\Models\Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'introduction',
            'lesson_type' => \App\Modules\Learning\Domain\Enums\LessonType::Theory,
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['ar' => 'المقدمة', 'en' => 'Introduction'],
        ]);

        $importer = app(WordPressCourseImporter::class);
        $this->assertSame(WordPressCourseImporter::TREE_POLICY_ABSORB, $importer->courseTreePolicy('8507'));

        $importer->mapExistingCourse('8507', $course->id, [
            'tree_policy' => WordPressCourseImporter::TREE_POLICY_ABSORB,
            'note' => 'Option A MAP_EXISTING_AND_IMPORT_CONTENT',
            'import_strategy' => 'MAP_EXISTING_AND_IMPORT_CONTENT',
        ]);

        $map = LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '8507')->first();
        $this->assertTrue($map->wasMappedToExisting());
        $this->assertFalse($map->wasCreatedByMigration());
        $this->assertSame($run->id, $map->migration_run_id);

        $postsById = [
            90001 => (object) [
                'ID' => 90001,
                'post_title' => 'WP Lesson One',
                'post_name' => 'wp-lesson-one',
                'post_content' => 'content-1',
                'post_status' => 'publish',
            ],
            90002 => (object) [
                'ID' => 90002,
                'post_title' => 'WP Topic One',
                'post_name' => 'wp-topic-one',
                'post_content' => '',
                'post_status' => 'publish',
            ],
            90003 => (object) [
                'ID' => 90003,
                'post_title' => 'WP Lesson Two',
                'post_name' => 'wp-lesson-two',
                'post_content' => 'content-2',
                'post_status' => 'publish',
            ],
        ];

        $result = $importer->persistCourseTree([
            'legacy_id' => '8507',
            'title' => 'كورس الميكروسكوب',
            'slug' => 'wp-arabic-slug-not-local',
            'content' => 'wp tree',
            'status' => 'publish',
            'flags' => [],
            'tree_policy' => WordPressCourseImporter::TREE_POLICY_ABSORB,
            'lessons' => [
                [
                    'legacy_id' => '90001',
                    'sort_order' => 0,
                    'lesson_level_quiz_ids' => [101, 102],
                    'topics' => [
                        ['legacy_id' => '90002', 'sort_order' => 0],
                    ],
                ],
                [
                    'legacy_id' => '90003',
                    'sort_order' => 1,
                    'lesson_level_quiz_ids' => [],
                    'topics' => [],
                ],
            ],
        ], $postsById);

        $this->assertFalse($result['course_created']);
        $this->assertFalse($result['tree_persist_suppressed']);
        $this->assertTrue($result['native_lessons_preserved']);
        $this->assertSame(WordPressCourseImporter::TREE_POLICY_ABSORB, $result['tree_policy']);
        $this->assertSame(2, $result['lessons_created']);
        $this->assertSame(1, $result['topics_created']);
        $this->assertSame(2, $result['lesson_quizzes_inventoried']);
        $this->assertSame(1, $result['sort_order_offset']);

        $this->assertNotNull($nativeLesson->fresh());
        $this->assertSame('introduction', $nativeLesson->fresh()->slug);
        $this->assertSame(3, \App\Modules\Learning\Infrastructure\Persistence\Models\Lesson::query()->where('course_id', $course->id)->count());

        $lessonMap = LegacyImportMap::query()->where('entity_type', 'lesson')->where('legacy_id', '90001')->first();
        $this->assertTrue($lessonMap->wasCreatedByMigration());
        $this->assertFalse($lessonMap->wasMappedToExisting());
        $this->assertTrue((bool) ($lessonMap->metadata['mapped_to_existing_parent'] ?? false));
        $this->assertSame('8507', $lessonMap->metadata['parent_course_legacy_id']);
        $this->assertSame($run->id, $lessonMap->migration_run_id);

        $importedLesson = \App\Modules\Learning\Infrastructure\Persistence\Models\Lesson::query()->find($lessonMap->local_id);
        $this->assertSame(2, $importedLesson->sort_order); // offset 1 + 1 + wp 0
        $secondMap = LegacyImportMap::query()->where('entity_type', 'lesson')->where('legacy_id', '90003')->first();
        $this->assertSame(3, \App\Modules\Learning\Infrastructure\Persistence\Models\Lesson::query()->find($secondMap->local_id)->sort_order);

        // Idempotent re-run: skip already mapped children.
        $rerun = $importer->persistCourseTree([
            'legacy_id' => '8507',
            'title' => 'كورس الميكروسكوب',
            'slug' => 'wp-arabic-slug-not-local',
            'content' => 'wp tree',
            'status' => 'publish',
            'flags' => [],
            'tree_policy' => WordPressCourseImporter::TREE_POLICY_ABSORB,
            'lessons' => [
                [
                    'legacy_id' => '90001',
                    'sort_order' => 0,
                    'lesson_level_quiz_ids' => [101],
                    'topics' => [
                        ['legacy_id' => '90002', 'sort_order' => 0],
                    ],
                ],
                [
                    'legacy_id' => '90003',
                    'sort_order' => 1,
                    'lesson_level_quiz_ids' => [],
                    'topics' => [],
                ],
            ],
        ], $postsById);
        $this->assertSame(0, $rerun['lessons_created']);
        $this->assertSame(2, $rerun['lessons_skipped']);
        $this->assertSame(1, $rerun['topics_skipped']);

        app(MigrationRunService::class)->rollback($run, false);

        $this->assertNull($course->fresh()->deleted_at);
        $this->assertSame('microscope-course', $course->fresh()->slug);
        $this->assertNotNull($nativeLesson->fresh());
        $this->assertSame(1, \App\Modules\Learning\Infrastructure\Persistence\Models\Lesson::query()->where('course_id', $course->id)->count());
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'lesson')->count());
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'topic')->count());
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '8507')->count());
        $this->assertSame(0, \App\Modules\Learning\Infrastructure\Persistence\Models\Topic::query()->count());
    }

    public function test_suppress_tree_policy_still_creates_zero_lessons_when_configured(): void
    {
        $run = app(MigrationRunService::class)->start('testing-suppress-tree-generic');
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'كورس الميكروسكوب'],
        ]);

        $importer = app(WordPressCourseImporter::class);
        $importer->mapExistingCourse('8507', $course->id, [
            'tree_policy' => WordPressCourseImporter::TREE_POLICY_SUPPRESS,
        ]);

        $result = $importer->persistCourseTree([
            'legacy_id' => '8507',
            'title' => 'كورس الميكروسكوب',
            'slug' => 'wp-arabic-slug-not-local',
            'content' => 'wp tree',
            'status' => 'publish',
            'flags' => [],
            'tree_policy' => WordPressCourseImporter::TREE_POLICY_SUPPRESS,
            'lessons' => [
                [
                    'legacy_id' => '90001',
                    'sort_order' => 1,
                    'lesson_level_quiz_ids' => [101],
                    'topics' => [
                        ['legacy_id' => '90002', 'sort_order' => 1],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['tree_persist_suppressed']);
        $this->assertSame(0, $result['lessons_created']);
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'lesson')->count());
        $this->assertSame($run->id, LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '8507')->value('migration_run_id'));
    }
}
