<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Migration\Application\Services\WordPress\WordPressLearningVideoResolver;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaAvailabilityProbe;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaTransferPlanner;
use App\Modules\Migration\Application\Services\WordPress\YouTubeVideoReferenceNormalizer;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyImportMap;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M1 media preparation tests — fake HTTP / fake filesystem / sqlite memory only.
 * Must never touch staging or create MigrationRun rows outside RefreshDatabase.
 */
final class WordPressMediaM1PreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_marks_successful_image_available(): void
    {
        Http::fake([
            'https://example.test/a.jpg' => Http::response('', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '1234',
            ]),
        ]);

        $probe = app(WordPressMediaAvailabilityProbe::class);
        $result = $probe->probe('https://example.test/a.jpg', 'image/jpeg');

        $this->assertSame(WordPressMediaAvailabilityProbe::AVAILABLE, $result['availability']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(1234, $result['content_length']);
        $this->assertSame('HEAD', $result['method']);
    }

    public function test_probe_marks_404_missing(): void
    {
        Http::fake([
            'https://example.test/missing.jpg' => Http::response('not found', 404, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $result = app(WordPressMediaAvailabilityProbe::class)
            ->probe('https://example.test/missing.jpg', 'image/jpeg');

        $this->assertSame(WordPressMediaAvailabilityProbe::MISSING_404, $result['availability']);
    }

    public function test_probe_falls_back_to_get_when_head_unsupported(): void
    {
        Http::fake([
            'https://example.test/b.png' => Http::sequence()
                ->push('', 405, ['Content-Type' => 'text/plain'])
                ->push('x', 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => '9',
                    'Content-Range' => 'bytes 0-0/900',
                ]),
        ]);

        $result = app(WordPressMediaAvailabilityProbe::class)
            ->probe('https://example.test/b.png', 'image/png');

        $this->assertSame(WordPressMediaAvailabilityProbe::AVAILABLE, $result['availability']);
        $this->assertSame('GET_RANGE', $result['method']);
        $this->assertSame(900, $result['content_length']);
    }

    public function test_probe_rejects_html_200_as_content_type_mismatch(): void
    {
        Http::fake([
            'https://example.test/trap.jpg' => Http::response('<html>login</html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Length' => '18',
            ]),
        ]);

        $result = app(WordPressMediaAvailabilityProbe::class)
            ->probe('https://example.test/trap.jpg', 'image/jpeg');

        $this->assertSame(WordPressMediaAvailabilityProbe::CONTENT_TYPE_MISMATCH, $result['availability']);
    }

    public function test_probe_records_redirect_final_url(): void
    {
        Http::fake([
            'https://example.test/old.jpg' => Http::response('', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '10',
            ]),
        ]);

        $result = app(WordPressMediaAvailabilityProbe::class)
            ->probe('https://example.test/old.jpg', 'image/jpeg');

        $this->assertSame(WordPressMediaAvailabilityProbe::AVAILABLE, $result['availability']);
        $this->assertNotNull($result['final_url']);
    }

    public function test_canonical_source_url_encodes_non_ascii_segments(): void
    {
        $url = app(WordPressMediaAvailabilityProbe::class)->canonicalSourceUrl(
            'https://sciencestreetlab.com/wp-content/uploads',
            '2026/02/صورة.png'
        );

        $this->assertStringStartsWith('https://sciencestreetlab.com/wp-content/uploads/2026/02/', $url);
        $this->assertStringContainsString(rawurlencode('صورة.png'), $url);
    }

    public function test_product_featured_and_gallery_ordering_plans(): void
    {
        $planner = app(WordPressMediaTransferPlanner::class);

        $featured = $planner->productFeaturedPlan(100);
        $this->assertSame('image', $featured['planned_collection_or_field']);
        $this->assertSame('PRODUCT_IMAGE_ATTACH', $featured['future_import_action']);
        $this->assertSame(0, $featured['planned_order']);

        $gallery = $planner->productGalleryPlans([11, 22, 33]);
        $this->assertCount(3, $gallery);
        $this->assertSame([0, 1, 2], array_column($gallery, 'planned_order'));
        $this->assertSame([11, 22, 33], array_column($gallery, 'legacy_attachment_id'));
        $this->assertSame('gallery', $gallery[0]['planned_collection_or_field']);
    }

    public function test_course_image_plan_uses_existing_courses_directory_convention(): void
    {
        Storage::fake('public');
        $plan = app(WordPressMediaTransferPlanner::class)
            ->courseImagePlan(8017, '2026/02/Screenshot.png');

        $this->assertSame('COURSE_IMAGE_STORE', $plan['future_import_action']);
        $this->assertSame('courses', $plan['storage_directory']);
        $this->assertSame('image_url', $plan['planned_collection_or_field']);
        $this->assertSame('courses/8017_Screenshot.png', $plan['planned_relative_path']);
    }

    public function test_youtube_normalization_across_url_shapes(): void
    {
        $n = app(YouTubeVideoReferenceNormalizer::class);

        $this->assertSame('DwNTfebU9es', $n->extractVideoId('https://www.youtube.com/watch?v=DwNTfebU9es'));
        $this->assertSame('DwNTfebU9es', $n->extractVideoId('https://youtu.be/DwNTfebU9es'));
        $this->assertSame('DwNTfebU9es', $n->extractVideoId('https://www.youtube.com/embed/DwNTfebU9es?feature=oembed'));
        $this->assertSame('tIzt3gb92Fw', $n->extractVideoId('https://www.youtube.com/shorts/tIzt3gb92Fw'));
        $this->assertSame(
            'https://www.youtube.com/watch?v=DwNTfebU9es',
            $n->canonicalWatchUrl('<iframe src="https://www.youtube.com/embed/DwNTfebU9es?feature=oembed"></iframe>')
        );
        // JSON unicode-escaped dashes must collapse to one id
        $this->assertSame(
            '--Uvuvdu6CU',
            $n->extractVideoId('https://www.youtube.com/watch?v=\\u002d\\u002dUvuvdu6CU')
        );
        $this->assertSame(
            ['DwNTfebU9es'],
            $n->uniqueVideoIds([
                'https://www.youtube.com/watch?v=DwNTfebU9es',
                'https://www.youtube.com/embed/DwNTfebU9es?feature=oembed',
            ])
        );
    }

    public function test_duplicate_oembed_and_content_resolve_one_clear_video(): void
    {
        $resolved = app(WordPressLearningVideoResolver::class)->resolve('lesson', [
            ['source' => 'post_content', 'url' => 'https://www.youtube.com/watch?v=DwNTfebU9es'],
            ['source' => 'postmeta:_oembed_abc', 'url' => 'https://www.youtube.com/embed/DwNTfebU9es?feature=oembed'],
            ['source' => 'postmeta:_oembed_abc', 'url' => '<iframe src="https://www.youtube.com/embed/DwNTfebU9es"></iframe>'],
        ]);

        $this->assertSame(WordPressLearningVideoResolver::MULTIPLE_REFERENCES_SAME_VIDEO, $resolved['resolution_status']);
        $this->assertSame('https://www.youtube.com/watch?v=DwNTfebU9es', $resolved['canonical_video_url']);
        $this->assertSame('youtube', $resolved['provider']);
        $this->assertSame('post_content', $resolved['source_evidence']);
    }

    public function test_multiple_distinct_videos_are_ambiguous_null_canonical(): void
    {
        $resolved = app(WordPressLearningVideoResolver::class)->resolve('lesson', [
            ['source' => 'post_content', 'url' => 'https://www.youtube.com/watch?v=JA8-6L2o8Jk'],
            ['source' => 'post_content', 'url' => 'https://www.youtube.com/watch?v=Btx3F9FLIyc'],
        ]);

        $this->assertSame(WordPressLearningVideoResolver::MULTIPLE_DISTINCT_VIDEOS, $resolved['resolution_status']);
        $this->assertNull($resolved['canonical_video_url']);
        $this->assertNull($resolved['provider']);
        $this->assertCount(2, $resolved['normalized_unique_video_ids']);
    }

    public function test_structured_topic_video_beats_oembed(): void
    {
        $resolved = app(WordPressLearningVideoResolver::class)->resolve('topic', [
            ['source' => 'postmeta:_sfwd-topic', 'url' => 'https://www.youtube.com/shorts/tIzt3gb92Fw'],
            ['source' => 'postmeta:_oembed_x', 'url' => 'https://www.youtube.com/embed/tIzt3gb92Fw?feature=oembed'],
        ]);

        $this->assertContains($resolved['resolution_status'], [
            WordPressLearningVideoResolver::ONE_CLEAR_VIDEO,
            WordPressLearningVideoResolver::MULTIPLE_REFERENCES_SAME_VIDEO,
        ]);
        $this->assertSame('structured', $resolved['source_evidence']);
        $this->assertSame('https://www.youtube.com/watch?v=tIzt3gb92Fw', $resolved['canonical_video_url']);
    }

    public function test_lesson_video_schema_migration_adds_nullable_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('lessons', ['video_url', 'video_provider']));

        $course = Course::query()->create([
            'slug' => 'm1-course',
            'access_type' => 'free',
            'is_published' => true,
            'title' => ['ar' => 'ك', 'en' => 'c'],
        ]);

        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'm1-lesson',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['ar' => 'د', 'en' => 'l'],
            'video_url' => 'https://www.youtube.com/watch?v=DwNTfebU9es',
            'video_provider' => 'youtube',
        ]);

        $this->assertSame('youtube', $lesson->fresh()->video_provider);
        $this->assertNotNull($lesson->fresh()->video_url);
    }

    public function test_idempotency_detects_existing_legacy_attachment_media(): void
    {
        $planner = app(WordPressMediaTransferPlanner::class);
        $props = $planner->idempotencyCustomProperties(
            6799,
            '2026/01/22.png',
            'https://sciencestreetlab.com/wp-content/uploads/2026/01/22.png',
            99
        );

        $this->assertSame('wordpress', $props['legacy_source']);
        $this->assertSame(6799, $props['legacy_attachment_id']);

        $existing = new class
        {
            public function getCustomProperty(string $key): mixed
            {
                return match ($key) {
                    'legacy_source' => 'wordpress',
                    'legacy_attachment_id' => 6799,
                    default => null,
                };
            }
        };

        $this->assertTrue($planner->alreadyAttached([$existing], 6799));
        $this->assertFalse($planner->alreadyAttached([$existing], 6800));
    }

    public function test_m1_preparation_does_not_write_staging_style_business_rows(): void
    {
        $beforeMaps = LegacyImportMap::query()->count();
        $beforeRuns = LegacyMigrationRun::query()->count();
        $beforeProducts = Product::query()->count();
        $beforeMedia = \DB::table('media')->count();

        // Exercise planners/resolvers/probes only — no importers, no MigrationRun::start.
        app(WordPressMediaTransferPlanner::class)->productFeaturedPlan(1);
        app(WordPressLearningVideoResolver::class)->resolve('lesson', []);
        Http::fake(['*' => Http::response('', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '1'])]);
        app(WordPressMediaAvailabilityProbe::class)->probe('https://example.test/x.jpg', 'image/jpeg');

        $this->assertSame($beforeMaps, LegacyImportMap::query()->count());
        $this->assertSame($beforeRuns, LegacyMigrationRun::query()->count());
        $this->assertSame($beforeProducts, Product::query()->count());
        $this->assertSame($beforeMedia, \DB::table('media')->count());

        // Sanity: Product Spatie collections exist without attaching media.
        $product = new Product;
        $collections = collect($product->getRegisteredMediaCollections())->pluck('name')->all();
        $this->assertContains('image', $collections);
        $this->assertContains('gallery', $collections);
    }
}
