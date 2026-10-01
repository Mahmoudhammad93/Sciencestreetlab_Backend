<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaLocalSource;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaOwnershipResolver;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

final class WordPressMediaR87ASafetyTest extends TestCase
{
    use RefreshDatabase;

    private function runningRun(): LegacyMigrationRun
    {
        return LegacyMigrationRun::query()->create([
            'source' => 'wordpress',
            'environment' => 'testing',
            'status' => 'running',
            'notes' => ['note' => 'r87a'],
            'started_at' => now(),
        ]);
    }

    private function map(int $runId, string $type, string $legacyId, int $localId): void
    {
        LegacyImportMap::query()->create([
            'migration_run_id' => $runId,
            'source' => LegacyImportMapRepository::SOURCE_WORDPRESS,
            'entity_type' => $type,
            'legacy_id' => $legacyId,
            'local_id' => $localId,
            'metadata' => LegacyImportMapRepository::ownershipCreated([]),
            'imported_at' => now(),
        ]);
    }

    public function test_course_8507_resolves_production_map_not_staging_or_micro(): void
    {
        $run = $this->runningRun();

        $nativeUnrelated = Course::query()->create([
            'slug' => 'prod-station-light-sound',
            'access_type' => 'free',
            'is_published' => true,
            'title' => ['ar' => 'native-1', 'en' => 'native-1'],
        ]);
        $micro = Course::query()->create([
            'slug' => 'micro',
            'access_type' => 'free',
            'is_published' => true,
            'title' => ['ar' => 'micro', 'en' => 'micro'],
        ]);
        $historical = Course::query()->create([
            'slug' => 'historical-8507',
            'access_type' => 'free',
            'is_published' => true,
            'title' => ['ar' => 'microscope-wp', 'en' => 'microscope-wp'],
        ]);

        // Staging plan used local_entity_id=1 (first native). Production map points at historical.
        $this->assertSame(1, $nativeUnrelated->id);
        $this->map($run->id, 'course', '8507', $historical->id);

        $resolved = app(WordPressMediaOwnershipResolver::class)
            ->resolve($run->id, 'course', '8507');

        $this->assertSame(WordPressMediaOwnershipResolver::STATUS_OK, $resolved['status']);
        $this->assertSame($historical->id, $resolved['local_id']);
        $this->assertNotSame($nativeUnrelated->id, $resolved['local_id']);
        $this->assertNotSame($micro->id, $resolved['local_id']);
        $this->assertNotSame(1, $resolved['local_id']); // staging plan ID must not win

        app(WordPressMediaOwnershipResolver::class)
            ->assertStagingIdNotAuthoritative(1, (int) $resolved['local_id']);
    }

    public function test_product_course_lesson_topic_resolution_and_6912_skip_policy(): void
    {
        $run = $this->runningRun();
        $product = Product::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sku' => 'SKU-R87A',
            'slug' => 'prod-r87a',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 10,
            'currency' => 'EGP',
            'name' => ['ar' => 'p', 'en' => 'p'],
            'published_at' => now(),
        ]);
        $course = Course::query()->create([
            'slug' => 'c-r87a',
            'access_type' => 'free',
            'is_published' => true,
            'title' => ['ar' => 'c', 'en' => 'c'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'l-r87a',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['ar' => 'l', 'en' => 'l'],
        ]);
        $topic = Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => 't-r87a',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['ar' => 't', 'en' => 't'],
        ]);

        $this->map($run->id, 'product', '6811', $product->id);
        $this->map($run->id, 'course', '8481', $course->id);
        $this->map($run->id, 'lesson', '8575', $lesson->id);
        $this->map($run->id, 'topic', '9089', $topic->id);

        $resolver = app(WordPressMediaOwnershipResolver::class);
        $this->assertSame($product->id, $resolver->resolve($run->id, 'product', '6811')['local_id']);
        $this->assertSame($course->id, $resolver->resolve($run->id, 'course', '8481')['local_id']);
        $this->assertSame($lesson->id, $resolver->resolve($run->id, 'lesson', '8575')['local_id']);
        $this->assertSame($topic->id, $resolver->resolve($run->id, 'topic', '9089')['local_id']);

        // Missing map (6912) → missing, never attach.
        $missing = $resolver->resolve($run->id, 'product', '6912');
        $this->assertSame(WordPressMediaOwnershipResolver::STATUS_MISSING, $missing['status']);
        $this->assertSame(6912, WordPressMediaImporter::SKIPPED_LEGACY_PRODUCT_ID);
    }

    public function test_wrong_entity_type_and_missing_map(): void
    {
        $run = $this->runningRun();
        $resolver = app(WordPressMediaOwnershipResolver::class);

        $wrong = $resolver->resolve($run->id, 'competition', '4');
        $this->assertSame(WordPressMediaOwnershipResolver::STATUS_WRONG_TYPE, $wrong['status']);

        $missing = $resolver->resolve($run->id, 'lesson', '8701');
        $this->assertSame(WordPressMediaOwnershipResolver::STATUS_MISSING, $missing['status']);

        // Map on a different run must not resolve for the requested run.
        $course = Course::query()->create([
            'slug' => 'other-run-course',
            'access_type' => 'free',
            'is_published' => true,
            'title' => ['ar' => 'a', 'en' => 'a'],
        ]);
        $other = $this->runningRun();
        $this->map($other->id, 'course', '7777', $course->id);
        $cross = $resolver->resolve($run->id, 'course', '7777');
        $this->assertSame(WordPressMediaOwnershipResolver::STATUS_MISSING, $cross['status']);
    }

    public function test_local_source_hash_path_safety_and_no_http_fallback(): void
    {
        $root = storage_path('app/testing/r87a-media-source');
        @mkdir($root.'/files/42', 0755, true);
        $file = $root.'/files/42/sample.png';
        file_put_contents($file, 'hello-media');
        $sha = hash_file('sha256', $file);
        $size = filesize($file);
        $manifest = $root.'/media-sha256.manifest';
        file_put_contents($manifest, "42/sample.png\t{$size}\t{$sha}\n");

        config([
            'wordpress.media.source_root' => $root,
            'wordpress.media.hash_manifest' => $manifest,
            'wordpress.media.allow_http' => false,
        ]);

        $src = app(WordPressMediaLocalSource::class);
        $path = $src->resolveSourceFile(42, 'sample.png');
        $this->assertSame(realpath($file), $path);
        $ok = $src->verifyFile(42, 'sample.png', (string) $path);
        $this->assertTrue($ok['ok']);

        file_put_contents($file, 'hello-media!!'); // same length as hello-media (11 chars) -> hash mismatch without size change
        // Restore exact size: 'hello-media' is 11 bytes; use another 11-byte payload
        file_put_contents($file, 'HELLO-MEDIA');
        $bad = $src->verifyFile(42, 'sample.png', (string) realpath($file));
        $this->assertFalse($bad['ok']);
        $this->assertContains($bad['code'], ['HASH_MISMATCH', 'SIZE_MISMATCH']);

        $this->expectException(\RuntimeException::class);
        $src->assertSafeRelativePath('../etc/passwd');
    }

    public function test_transfer_one_uses_local_source_without_http(): void
    {
        $run = $this->runningRun();
        $root = storage_path('app/testing/r87a-transfer');
        @mkdir($root.'/files/99', 0755, true);
        $file = $root.'/files/99/a.jpg';
        file_put_contents($file, 'abc123');
        $sha = hash_file('sha256', $file);
        $size = filesize($file);
        $manifest = $root.'/media-sha256.manifest';
        file_put_contents($manifest, "99/a.jpg\t{$size}\t{$sha}\n");

        config([
            'wordpress.media.source_root' => $root,
            'wordpress.media.hash_manifest' => $manifest,
            'wordpress.media.allow_http' => false,
        ]);

        Http::fake();

        $importer = app(WordPressMediaImporter::class);
        $method = new ReflectionMethod($importer, 'transferOne');
        $method->setAccessible(true);
        $work = storage_path('app/private/migration/wordpress-media/'.$run->id);
        $result = $method->invoke($importer, [
            'legacy_attachment_id' => 99,
            'relative_path' => '2026/01/a.jpg',
            'canonical_source_url' => 'https://sciencestreetlab.com/wp-content/uploads/2026/01/a.jpg',
            'mime' => 'image/jpeg',
            'content_length' => $size,
        ], $run->id, true, $work);

        $this->assertSame('would_use_local', $result['status']);
        $this->assertSame($sha, $result['sha256']);
        Http::assertNothingSent();

        $missing = $method->invoke($importer, [
            'legacy_attachment_id' => 100,
            'relative_path' => '2026/01/missing.jpg',
            'canonical_source_url' => 'https://sciencestreetlab.com/wp-content/uploads/2026/01/missing.jpg',
            'mime' => 'image/jpeg',
            'content_length' => 1,
        ], $run->id, true, $work);
        $this->assertSame('failed', $missing['status']);
        $this->assertSame('MISSING_LOCAL_SOURCE', $missing['code']);
        Http::assertNothingSent();
    }

    public function test_persist_gate_blocks_execute_without_flag(): void
    {
        config(['wordpress.real_persist' => null]);
        $run = $this->runningRun();
        $this->artisan('migration:wordpress:media', [
            '--migration-run' => $run->id,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_path_traversal_rejected_by_local_source(): void
    {
        $root = storage_path('app/testing/r87a-traversal');
        @mkdir($root.'/files/1', 0755, true);
        config([
            'wordpress.media.source_root' => $root,
            'wordpress.media.allow_http' => false,
        ]);
        $this->expectException(\RuntimeException::class);
        app(WordPressMediaLocalSource::class)->assertSafeRelativePath('foo/../../etc/passwd');
    }

    public function test_competition_media_constant_scope_excluded(): void
    {
        // Hard exclusion: importer report field defaults / never imports competition physical media.
        $this->assertSame(6912, WordPressMediaImporter::SKIPPED_LEGACY_PRODUCT_ID);
        $this->assertContains('product', WordPressMediaOwnershipResolver::ALLOWED_ENTITY_TYPES);
        $this->assertNotContains('competition', WordPressMediaOwnershipResolver::ALLOWED_ENTITY_TYPES);
        $this->assertNotContains('competition_submission', WordPressMediaOwnershipResolver::ALLOWED_ENTITY_TYPES);
    }
}
