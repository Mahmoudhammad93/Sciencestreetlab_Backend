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
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressCollisionAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressCompetitionImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressCourseImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressEnrollmentImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Persist-path unit tests for course / enrollment / competition importers.
 * Does NOT connect to wordpress_legacy or run real import commands.
 */
final class WordPressMigrationPersistTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_tree_persist_creates_course_lessons_topic_and_maps(): void
    {
        $run = app(MigrationRunService::class)->start('testing-courses');
        $importer = app(WordPressCourseImporter::class);

        $postsById = [
            1001 => (object) [
                'ID' => 1001,
                'post_title' => 'Lesson One',
                'post_name' => 'lesson-one',
                'post_content' => 'L1',
                'post_status' => 'publish',
            ],
            1002 => (object) [
                'ID' => 1002,
                'post_title' => 'Lesson Two',
                'post_name' => 'lesson-two',
                'post_content' => 'L2',
                'post_status' => 'publish',
            ],
            2001 => (object) [
                'ID' => 2001,
                'post_title' => 'Only Topic',
                'post_name' => 'only-topic',
                'post_content' => 'T1',
                'post_status' => 'publish',
            ],
        ];

        $result = $importer->persistCourseTree([
            'legacy_id' => '8515',
            'title' => 'كورس كرة برونللي',
            'slug' => 'brunei-ball-course',
            'content' => 'desc',
            'status' => 'publish',
            'relationship_source' => 'ld_course_steps',
            'flags' => [],
            'lessons' => [
                [
                    'legacy_id' => 1001,
                    'sort_order' => 0,
                    'topics' => [
                        ['legacy_id' => 2001, 'sort_order' => 0],
                    ],
                    'lesson_level_quiz_ids' => [9001, 9002],
                ],
                [
                    'legacy_id' => 1002,
                    'sort_order' => 1,
                    'topics' => [],
                    'lesson_level_quiz_ids' => [],
                ],
            ],
        ], $postsById);

        $this->assertTrue($result['course_created']);
        $this->assertSame(2, $result['lessons_created']);
        $this->assertSame(1, $result['topics_created']);
        $this->assertSame(2, $result['lesson_quizzes_inventoried']);
        $this->assertSame(1, Course::query()->count());
        $this->assertSame(2, Lesson::query()->count());
        $this->assertSame(1, Topic::query()->count());
        $this->assertSame(0, Lesson::query()->first()->sort_order);
        $this->assertSame(1, Lesson::query()->orderByDesc('sort_order')->first()->sort_order);

        $courseMap = LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '8515')->first();
        $this->assertNotNull($courseMap);
        $this->assertSame($run->id, $courseMap->migration_run_id);
        $this->assertSame('8515', $courseMap->metadata['legacy_wordpress_id']);
        $this->assertTrue($courseMap->metadata['created_by_migration']);
        $this->assertFalse($courseMap->metadata['mapped_to_existing']);
        $this->assertTrue($courseMap->wasCreatedByMigration());
        $this->assertFalse($courseMap->wasMappedToExisting());

        $lessonMap = LegacyImportMap::query()->where('entity_type', 'lesson')->where('legacy_id', '1001')->first();
        $this->assertNotNull($lessonMap);
        $this->assertTrue($lessonMap->metadata['created_by_migration']);
        $this->assertSame([9001, 9002], $lessonMap->metadata['lesson_level_quiz_ids']);
        $this->assertSame('QUIZ_PERSIST_REQUIRES_SEPARATE_PHASE', $lessonMap->metadata['quiz_persist']);
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'quiz')->count());

        // Idempotent re-run
        $again = $importer->persistCourseTree([
            'legacy_id' => '8515',
            'title' => 'كورس كرة برونللي',
            'slug' => 'brunei-ball-course',
            'content' => 'desc',
            'status' => 'publish',
            'relationship_source' => 'ld_course_steps',
            'flags' => [],
            'lessons' => [
                ['legacy_id' => 1001, 'sort_order' => 0, 'topics' => [['legacy_id' => 2001, 'sort_order' => 0]], 'lesson_level_quiz_ids' => []],
                ['legacy_id' => 1002, 'sort_order' => 1, 'topics' => [], 'lesson_level_quiz_ids' => []],
            ],
        ], $postsById);
        $this->assertFalse($again['course_created']);
        $this->assertSame(0, $again['lessons_created']);
        $this->assertSame(1, Course::query()->count());
    }

    public function test_empty_source_course_38568_persists_with_zero_lessons(): void
    {
        $importer = app(WordPressCourseImporter::class);
        $result = $importer->persistCourseTree([
            'legacy_id' => '38568',
            'title' => 'كورس روبوت الجرافيتي',
            'slug' => 'graffiti-robot-course',
            'content' => '',
            'status' => 'publish',
            'relationship_source' => 'empty_published_course',
            'flags' => ['EMPTY_SOURCE_COURSE'],
            'lessons' => [],
        ]);

        $this->assertTrue($result['course_created']);
        $this->assertSame(0, $result['lessons_created']);
        $course = Course::query()->first();
        $this->assertTrue($course->is_published);
        $map = LegacyImportMap::query()->where('entity_type', 'course')->where('legacy_id', '38568')->first();
        $this->assertTrue($map->metadata['empty_source_course']);
        $this->assertContains('EMPTY_SOURCE_COURSE', $map->metadata['flags']);
    }

    public function test_course_slug_collision_without_map_does_not_overwrite(): void
    {
        Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'native-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Native'],
        ]);

        $result = app(WordPressCourseImporter::class)->persistCourseTree([
            'legacy_id' => '99999',
            'title' => 'WP Course',
            'slug' => 'native-course',
            'content' => '',
            'status' => 'publish',
            'flags' => [],
            'lessons' => [],
        ]);

        $this->assertFalse($result['course_created']);
        $this->assertSame('MERGE_REQUIRES_DECISION', $result['code']);
        $this->assertSame(1, Course::query()->count());
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'course')->count());
    }

    public function test_enrollment_persist_creates_row_without_side_effects(): void
    {
        $run = app(MigrationRunService::class)->start('testing-enrollments');
        $user = User::factory()->create();
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'enroll-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Course'],
        ]);

        $repo = app(LegacyImportMapRepository::class);
        $repo->upsertMapping('user', '55', ['local_id' => $user->id]);
        $repo->upsertMapping('course', '8481', ['local_id' => $course->id]);

        $status = app(WordPressEnrollmentImporter::class)->persistEnrollment(
            '55:8481',
            $user->id,
            $course->id,
            '55',
            '8481',
            '1700000000',
            123,
        );

        $this->assertSame('created', $status);
        $enrollment = Enrollment::query()->first();
        $this->assertNotNull($enrollment);
        $this->assertSame($user->id, $enrollment->user_id);
        $this->assertSame($course->id, $enrollment->course_id);
        $this->assertSame('active', $enrollment->status->value);
        $this->assertEquals(1700000000, $enrollment->enrolled_at->getTimestamp());

        $map = LegacyImportMap::query()->where('entity_type', 'enrollment')->where('legacy_id', '55:8481')->first();
        $this->assertSame($run->id, $map->migration_run_id);
        $this->assertSame('1700000000', $map->metadata['access_from']);

        $again = app(WordPressEnrollmentImporter::class)->persistEnrollment(
            '55:8481',
            $user->id,
            $course->id,
            '55',
            '8481',
            '1700000000',
        );
        $this->assertSame('skipped_mapped', $again);
        $this->assertSame(1, Enrollment::query()->count());
        $this->assertNull($enrollment->course_plan_id);
        $this->assertFalse($map->metadata['side_effects']['enroll_user_service']);
        $this->assertFalse($map->metadata['side_effects']['email']);
        $this->assertFalse($map->metadata['side_effects']['whatsapp']);
        $this->assertFalse($map->metadata['side_effects']['bosta']);
        $this->assertFalse($map->metadata['side_effects']['payment']);
        $this->assertFalse($map->metadata['side_effects']['orders']);
    }

    public function test_enrollment_existing_user_course_pair_is_idempotent_via_map(): void
    {
        $user = User::factory()->create();
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'pair-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Course'],
        ]);
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => \App\Modules\Learning\Domain\Enums\EnrollmentStatus::Active,
            'progress_percent' => 10,
            'enrolled_at' => now()->subDay(),
            'started_at' => now()->subDay(),
            'grant_certificate' => false,
        ]);

        $status = app(WordPressEnrollmentImporter::class)->persistEnrollment(
            '77:9001',
            $user->id,
            $course->id,
            '77',
            '9001',
            '1700000001',
        );

        $this->assertSame('skipped_existing', $status);
        $this->assertSame(1, Enrollment::query()->count());
        $map = LegacyImportMap::query()->where('entity_type', 'enrollment')->where('legacy_id', '77:9001')->first();
        $this->assertNotNull($map);
        $this->assertSame('EXISTING_USER_COURSE_PAIR', $map->metadata['collision']);
        $this->assertTrue($map->wasMappedToExisting());
        $this->assertFalse($map->wasCreatedByMigration());
    }

    public function test_product_slug_collision_without_map_does_not_create(): void
    {
        $run = app(MigrationRunService::class)->start('testing-products');
        // Use a slug/legacy_id that is NOT in approved_map_existing (6811 is MAP_EXISTING).
        // This asserts unapproved slug overlap still refuses create.
        Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'sku' => 'LOCAL-EXISTING',
            'slug' => 'unapproved-collision-kit',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'name' => ['ar' => 'Local kit'],
        ]);

        $status = app(WordPressProductImporter::class)->persistProduct([
            'legacy_id' => '88801',
            'slug' => 'unapproved-collision-kit',
            'proposed_laravel_type' => ProductType::Kit->value,
            'name' => ['ar' => 'WP kit'],
            'wc_type' => 'simple',
        ], 'WP-88801');

        $this->assertSame('merge_requires_decision', $status);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, LegacyImportMap::query()->where('entity_type', 'product')->count());

        $ok = app(WordPressProductImporter::class)->persistProduct([
            'legacy_id' => '99901',
            'slug' => 'brand-new-wp-kit',
            'proposed_laravel_type' => ProductType::Kit->value,
            'name' => ['ar' => 'New kit'],
            'wc_type' => 'simple',
        ], 'WP-99901');

        $this->assertSame('created', $ok);
        $productMap = LegacyImportMap::query()->where('entity_type', 'product')->where('legacy_id', '99901')->first();
        $this->assertNotNull($productMap);
        $this->assertSame($run->id, $productMap->migration_run_id);
        $this->assertTrue($productMap->wasCreatedByMigration());
        $this->assertFalse($productMap->wasMappedToExisting());
    }

    public function test_competition_create_new_and_historical_null_slot_persist(): void
    {
        $run = app(MigrationRunService::class)->start('testing-competition');
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Microscope'],
        ]);
        app(LegacyImportMapRepository::class)->upsertMapping('course', '8507', ['local_id' => $course->id]);

        $importer = app(WordPressCompetitionImporter::class);
        $competition = $importer->persistCompetitionFromPayload([
            'legacy_id' => '4',
            'title' => 'تحدي ال100 صورة',
            'description' => 'desc',
            'required_photos' => 100,
            'photos_per_sample' => 2,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
            'prerequisite_course_id' => $course->id,
            'required_course_legacy_id' => '8507',
            'decision' => 'CREATE_NEW',
        ]);

        $this->assertNotNull($competition);
        $this->assertSame(100, $competition->required_photos);
        $map = LegacyImportMap::query()->where('entity_type', 'competition')->where('legacy_id', '4')->first();
        $this->assertSame($run->id, $map->migration_run_id);
        $this->assertSame('CREATE_NEW', $map->metadata['collision_decision']);

        $user = User::factory()->create();
        $participant = $importer->persistParticipant('31', $competition->id, $user->id, '99', 'active', now());
        $this->assertTrue($participant['created']);

        $result = $importer->persistSubmission(
            (object) [
                'id' => 501,
                'status' => 'approved',
                'sample_name' => 'specimen-a',
                'description' => 'd',
                'scientific_info' => 's',
                'created_at' => now(),
                'image_id' => 777,
                'reviewed_at' => now(),
                'reviewed_by' => 0,
            ],
            $participant['id'],
            1,
            1,
            0,
            2,
        );
        $this->assertTrue($result['created']);
        $this->assertSame('COMPETITION_SLOT_SCHEMA_RESOLVED', $result['code']);
        $this->assertSame(1, CompetitionSubmission::query()->count());

        $submission = CompetitionSubmission::query()->first();
        $this->assertNull($submission->sample_number);
        $this->assertNull($submission->photo_index);
        $this->assertSame('specimen-a', $submission->sample_name);
        $this->assertSame('approved', $submission->status->value);
        $this->assertSame('d', $submission->description);
        $this->assertSame('s', $submission->scientific_notes);
        $this->assertSame(0, $submission->getMedia('photo')->count());

        $subMap = LegacyImportMap::query()->where('entity_type', 'competition_submission')->where('legacy_id', '501')->first();
        $this->assertSame($submission->id, $subMap->local_id);
        $this->assertTrue($subMap->wasCreatedByMigration());
        $this->assertFalse($subMap->wasMappedToExisting());
        $this->assertSame('specimen-a', $subMap->metadata['sample_name']);
        $this->assertSame('d', $subMap->metadata['description']);
        $this->assertSame('s', $subMap->metadata['scientific_info']);
        $this->assertNull($subMap->metadata['sample_number']);
        $this->assertNull($subMap->metadata['photo_index']);
        $this->assertSame('COMPETITION_SLOT_SCHEMA_RESOLVED', $subMap->metadata['code']);
        $this->assertSame('MEDIA_PENDING', $subMap->metadata['media_status']);
        $this->assertFalse($subMap->metadata['physical_media_imported']);
        $this->assertSame($run->id, $subMap->migration_run_id);

        // Second historical row with null slots must not violate uk_submission_slot.
        $result2 = $importer->persistSubmission(
            (object) [
                'id' => 502,
                'status' => 'pending',
                'sample_name' => 'specimen-b',
                'description' => null,
                'scientific_info' => null,
                'created_at' => now(),
                'image_id' => 0,
                'reviewed_at' => null,
                'reviewed_by' => 0,
            ],
            $participant['id'],
            1,
            1,
            1,
            2,
        );
        $this->assertTrue($result2['created']);
        $this->assertSame(2, CompetitionSubmission::query()->count());
    }

    public function test_competition_map_existing_does_not_overwrite(): void
    {
        $run = app(MigrationRunService::class)->start('testing-competition-map');
        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'map-existing-prereq',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Prereq'],
        ]);
        $existing = Competition::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'seeded-100-challenge',
            'prerequisite_course_id' => $course->id,
            'required_photos' => 100,
            'photos_per_sample' => 2,
            'max_photos_per_sample' => 2,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
            'title' => ['ar' => 'Seeded local'],
            'description' => ['ar' => 'Do not overwrite'],
        ]);

        $importer = app(WordPressCompetitionImporter::class);
        $mapped = $importer->persistCompetitionFromPayload([
            'legacy_id' => '4',
            'title' => 'WP title must not overwrite',
            'required_photos' => 100,
            'photos_per_sample' => 2,
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'decision' => 'MAP_EXISTING',
            'existing_local_id' => $existing->id,
            'prerequisite_course_id' => 0,
            'required_course_legacy_id' => '8507',
        ]);

        $this->assertNotNull($mapped);
        $this->assertSame($existing->id, $mapped->id);
        $this->assertSame(1, Competition::query()->count());
        $fresh = $existing->fresh();
        $this->assertSame('Seeded local', $fresh->getTranslation('title', 'ar'));
        $this->assertSame('Do not overwrite', $fresh->getTranslation('description', 'ar'));

        $map = LegacyImportMap::query()->where('entity_type', 'competition')->where('legacy_id', '4')->first();
        $this->assertSame($existing->id, $map->local_id);
        $this->assertSame('MAP_EXISTING', $map->metadata['collision_decision']);
        $this->assertFalse($map->metadata['overwrite']);
        $this->assertFalse($map->metadata['created_by_migration']);
        $this->assertTrue($map->metadata['mapped_to_existing']);
        $this->assertFalse($map->wasCreatedByMigration());
        $this->assertTrue($map->wasMappedToExisting());
        $this->assertSame($run->id, $map->migration_run_id);
    }

    public function test_competition_decision_required_when_unspecified(): void
    {
        $comp = (object) [
            'id' => 4,
            'required_images' => 100,
            'required_course_id' => 8507,
        ];
        $resolved = app(WordPressCompetitionImporter::class)->resolveCompetitionLocalId(
            $comp,
            null,
            null,
            1,
        );
        $this->assertSame('decision_required', $resolved['status']);
        $this->assertSame('COMPETITION_DECISION_REQUIRED', $resolved['code']);
    }

    public function test_collision_analyzer_classifies_mapped_product_as_skipped(): void
    {
        $analyzer = app(WordPressCollisionAnalyzer::class);

        $this->assertSame(
            'MERGE_REQUIRES_DECISION',
            $analyzer->classifyProductCollision('6811', 'hydraulic-excavator-arm', true, false),
        );
        $this->assertSame(
            'NEW_RECORD',
            $analyzer->classifyProductCollision('99901', 'brand-new-kit', false, false),
        );
        $this->assertSame(
            'CONFLICT',
            $analyzer->classifyProductCollision('99902', 'other-slug', false, true),
        );

        app(LegacyImportMapRepository::class)->upsertMapping('product', '6811', [
            'local_id' => 42,
            'metadata' => ['collision_decision' => 'MAP_EXISTING'],
        ]);

        $this->assertSame(
            'SKIPPED_MAPPED',
            $analyzer->classifyProductCollision('6811', 'hydraulic-excavator-arm', true, false),
        );
    }

    public function test_collision_analyzer_classifies_mapped_competition_as_skipped(): void
    {
        $analyzer = app(WordPressCollisionAnalyzer::class);

        $this->assertSame(
            'MERGE_REQUIRES_DECISION',
            $analyzer->classifyCompetitionCollision('4', true),
        );
        $this->assertSame(
            'NEW_RECORD',
            $analyzer->classifyCompetitionCollision('4', false),
        );

        app(LegacyImportMapRepository::class)->upsertMapping('competition', '4', [
            'local_id' => 1,
            'metadata' => ['collision_decision' => 'MAP_EXISTING'],
        ]);

        $this->assertSame(
            'SKIPPED_MAPPED',
            $analyzer->classifyCompetitionCollision('4', true),
        );
    }
}
