<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CLI safety: real-persist gate, migration-run binding, rollback CLI defaults.
 */
final class WordPressMigrationCliSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wordpress.real_persist' => null]);
        ActiveMigrationRun::clear();
    }

    public function test_users_without_real_persist_refuses_and_writes_nothing(): void
    {
        $beforeUsers = User::query()->count();
        $beforeMaps = LegacyImportMap::query()->count();

        $this->artisan('migration:wordpress:users')
            ->assertFailed();

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
    }

    public function test_orders_without_real_persist_refuses_and_writes_nothing(): void
    {
        $beforeOrders = \App\Modules\Commerce\Infrastructure\Persistence\Models\Order::query()->count();
        $beforeMaps = LegacyImportMap::query()->count();

        $this->artisan('migration:wordpress:orders')
            ->assertFailed();

        $this->assertSame($beforeOrders, \App\Modules\Commerce\Infrastructure\Persistence\Models\Order::query()->count());
        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
    }

    public function test_all_mutating_importers_refuse_without_real_persist(): void
    {
        foreach ([
            'migration:wordpress:courses',
            'migration:wordpress:products',
            'migration:wordpress:enrollments',
            'migration:wordpress:competition',
        ] as $command) {
            $this->artisan($command)->assertFailed();
        }

        $this->artisan('migration:wordpress:apply-approved-collisions', ['--execute' => true])
            ->assertFailed();
    }

    public function test_dry_run_remains_zero_write_even_with_real_persist_enabled(): void
    {
        config(['wordpress.real_persist' => '1']);
        $beforeMaps = LegacyImportMap::query()->count();
        $beforeRuns = LegacyMigrationRun::query()->count();

        $this->artisan('migration:wordpress:users', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('migration:wordpress:orders', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('migration:wordpress:courses', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('migration:wordpress:products', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('migration:wordpress:enrollments', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('migration:wordpress:competition', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('migration:wordpress:apply-approved-collisions', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
        $this->assertSame($beforeRuns, LegacyMigrationRun::query()->count());
    }

    public function test_run_start_status_complete_cli(): void
    {
        $this->artisan('migration:wordpress:run:start', [
            '--environment' => 'testing',
            '--note' => 'cli-safety',
        ])->assertSuccessful();

        $run = LegacyMigrationRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('running', $run->status);
        $this->assertNull(ActiveMigrationRun::id());

        $this->artisan('migration:wordpress:run:status', ['run' => $run->id])->assertSuccessful();

        $this->artisan('migration:wordpress:run:complete', ['run' => $run->id])->assertSuccessful();
        $this->assertSame('completed', $run->fresh()->status);

        $this->artisan('migration:wordpress:run:complete', ['run' => $run->id])->assertFailed();
    }

    public function test_separate_command_with_migration_run_stamps_maps(): void
    {
        config(['wordpress.real_persist' => '1']);
        $run = app(MigrationRunService::class)->start('testing-stamp');
        ActiveMigrationRun::clear();

        $auth = app(WordPressRealPersistGate::class)->authorizeMutation($run->id, 'user');
        $this->assertTrue($auth['ok']);
        $this->assertSame($run->id, ActiveMigrationRun::id());

        $map = app(LegacyImportMapRepository::class)->upsertMapping('user', 'cli-stamp-1', [
            'local_id' => 42,
            'metadata' => LegacyImportMapRepository::ownershipCreated(['test' => true]),
        ]);
        $this->assertSame($run->id, $map->migration_run_id);
        ActiveMigrationRun::clear();
    }

    public function test_invalid_and_completed_run_rejected(): void
    {
        config(['wordpress.real_persist' => '1']);

        $auth = app(WordPressRealPersistGate::class)->authorizeMutation(999999, 'user');
        $this->assertFalse($auth['ok']);
        $this->assertSame(WordPressRealPersistGate::MIGRATION_RUN_INVALID, $auth['result']['code']);

        $run = app(MigrationRunService::class)->start('testing-complete');
        app(MigrationRunService::class)->complete($run);
        ActiveMigrationRun::clear();

        $auth2 = app(WordPressRealPersistGate::class)->authorizeMutation($run->id, 'user');
        $this->assertFalse($auth2['ok']);
        $this->assertSame(WordPressRealPersistGate::MIGRATION_RUN_NOT_ACTIVE, $auth2['result']['code']);
    }

    public function test_rollback_default_is_dry_run_zero_write(): void
    {
        $run = app(MigrationRunService::class)->start('testing-rb-dry');
        ActiveMigrationRun::clear();
        $user = User::factory()->create();
        app(LegacyImportMapRepository::class)->upsertMapping('user', 'rb-1', [
            'local_id' => $user->id,
            'migration_run_id' => $run->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([]),
        ]);

        $this->artisan('migration:wordpress:rollback', ['run' => $run->id])->assertSuccessful();

        $this->assertNotNull($user->fresh());
        $this->assertSame(1, LegacyImportMap::query()->where('migration_run_id', $run->id)->count());
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_rollback_execute_without_real_persist_refuses(): void
    {
        config(['wordpress.real_persist' => null]);
        $run = app(MigrationRunService::class)->start('testing-rb-refuse');
        ActiveMigrationRun::clear();

        $this->artisan('migration:wordpress:rollback', [
            'run' => $run->id,
            '--execute' => true,
        ])->assertFailed();

        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_authorized_rollback_deletes_migration_owned_only_and_preserves_map_existing(): void
    {
        config(['wordpress.real_persist' => '1']);
        $service = app(MigrationRunService::class);
        $run = $service->start('testing-rb-exec');

        $course = Course::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'title' => ['ar' => 'Microscope'],
        ]);
        $nativeLesson = Lesson::query()->create([
            'uuid' => (string) Str::uuid(),
            'course_id' => $course->id,
            'slug' => 'introduction',
            'type' => LessonType::Theory,
            'sort_order' => 0,
            'is_published' => true,
            'title' => ['en' => 'Introduction'],
        ]);
        Topic::query()->create([
            'uuid' => (string) Str::uuid(),
            'lesson_id' => $nativeLesson->id,
            'slug' => 'what-is-microscope',
            'sort_order' => 0,
            'is_published' => true,
            'title' => ['en' => 'What is a microscope'],
        ]);

        $competition = Competition::query()->create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'microscope-100-challenge',
            'title' => ['en' => '100'],
            'required_photos' => 100,
            'photos_per_sample' => 2,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'prerequisite_course_id' => $course->id,
        ]);

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'sku' => 'LOCAL-ARM',
            'slug' => 'hydraulic-excavator-arm',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 10,
            'currency' => 'EGP',
            'name' => ['ar' => 'Arm'],
            'published_at' => now(),
        ]);

        $maps = app(LegacyImportMapRepository::class);
        $maps->upsertMapping('course', '8507', [
            'local_id' => $course->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting(['collision_decision' => 'MAP_EXISTING']),
        ]);
        $maps->upsertMapping('competition', '4', [
            'local_id' => $competition->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting(['collision_decision' => 'MAP_EXISTING']),
        ]);
        $maps->upsertMapping('product', '6811', [
            'local_id' => $product->id,
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting(['collision_decision' => 'MAP_EXISTING']),
        ]);

        $importedLesson = Lesson::query()->create([
            'uuid' => (string) Str::uuid(),
            'course_id' => $course->id,
            'slug' => 'wp-imported-lesson',
            'type' => LessonType::Theory,
            'sort_order' => 10,
            'is_published' => true,
            'title' => ['ar' => 'WP Lesson'],
        ]);
        $maps->upsertMapping('lesson', 'wp-lesson-1', [
            'local_id' => $importedLesson->id,
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'parent_course_legacy_id' => '8507',
            ]),
        ]);

        ActiveMigrationRun::clear();

        $this->artisan('migration:wordpress:rollback', [
            'run' => $run->id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertNull($course->fresh()->deleted_at);
        $this->assertSame('microscope-course', $course->fresh()->slug);
        $this->assertNotNull(Lesson::query()->where('slug', 'introduction')->first());
        $this->assertNotNull(Topic::query()->where('slug', 'what-is-microscope')->first());
        $this->assertNull(Lesson::query()->where('slug', 'wp-imported-lesson')->first());
        $this->assertNotNull($competition->fresh());
        $this->assertSame('microscope-100-challenge', $competition->fresh()->slug);
        $this->assertNull($product->fresh()->deleted_at);
        $this->assertSame(0, LegacyImportMap::query()->where('migration_run_id', $run->id)->count());
        $this->assertSame('rolled_back', $run->fresh()->status);
    }
}
