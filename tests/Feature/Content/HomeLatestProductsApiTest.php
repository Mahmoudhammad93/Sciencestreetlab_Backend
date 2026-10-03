<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomeLatestProductsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_latest_published_kit_products_with_limit(): void
    {
        $this->makeProduct('draft-1', ProductStatus::Draft, ProductType::Kit, now()->subDays(1));

        $older = $this->makeProduct('older', ProductStatus::Published, ProductType::Kit, now()->subDays(5));
        $newest = $this->makeProduct('newest', ProductStatus::Published, ProductType::Kit, now()->subDay());
        $middle = $this->makeProduct('middle', ProductStatus::Published, ProductType::Kit, now()->subDays(2));

        $response = $this->getJson('/api/v1/home/latest-products?limit=2')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newest->id, $middle->id], $ids);
        $this->assertNotContains($older->id, $ids);
    }

    public function test_excludes_course_products_even_when_newer(): void
    {
        $kit = $this->makeProduct('kit-old', ProductStatus::Published, ProductType::Kit, now()->subDays(3));
        $course = $this->makeCourseProduct('course-new', now());

        $response = $this->getJson('/api/v1/home/latest-products?limit=4')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$kit->id], $ids);
        $this->assertNotContains($course->id, $ids);
        $this->assertSame('kit', $response->json('data.0.type'));
    }

    public function test_keeps_physical_kit_with_course_relationship_eligible(): void
    {
        $course = Course::query()->create([
            'slug' => 'micro-course',
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['ar' => 'كورس', 'en' => 'Course'],
            'short_description' => ['ar' => 'م', 'en' => 's'],
            'description' => ['ar' => 'و', 'en' => 'd'],
        ]);

        $kit = $this->makeProduct(
            'micro-kit',
            ProductStatus::Published,
            ProductType::Kit,
            now()->subDay(),
            courseId: $course->id,
            relatedCourseId: $course->id,
        );

        $response = $this->getJson('/api/v1/home/latest-products?limit=4')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($kit->id, $ids);
        $this->assertSame('kit', $response->json('data.0.type'));
    }

    public function test_returns_at_most_four_when_more_kits_exist(): void
    {
        foreach (range(1, 6) as $i) {
            $this->makeProduct("kit-{$i}", ProductStatus::Published, ProductType::Kit, now()->subMinutes($i));
        }

        $this->getJson('/api/v1/home/latest-products?limit=4')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_returns_fewer_than_four_without_filler(): void
    {
        $this->makeProduct('only-one', ProductStatus::Published, ProductType::Kit, now()->subDay());
        $this->makeCourseProduct('course-filler', now());

        $this->getJson('/api/v1/home/latest-products?limit=4')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_limit_is_clamped(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeProduct("p-{$i}", ProductStatus::Published, ProductType::Kit, now()->subMinutes($i));
        }

        $this->getJson('/api/v1/home/latest-products?limit=0')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/home/latest-products?limit=99')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    private function makeProduct(
        string $key,
        ProductStatus $status,
        ProductType $type,
        $publishedAt,
        ?int $courseId = null,
        ?int $relatedCourseId = null,
    ): Product {
        return Product::query()->create([
            'sku' => 'HOME-'.$key,
            'slug' => 'home-'.$key,
            'type' => $type,
            'status' => $status,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => $publishedAt,
            'course_id' => $courseId,
            'related_course_id' => $relatedCourseId,
            'name' => ['en' => $key, 'ar' => $key],
        ]);
    }

    private function makeCourseProduct(string $key, $publishedAt): Product
    {
        $course = Course::query()->create([
            'slug' => 'course-'.$key,
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['ar' => $key, 'en' => $key],
            'short_description' => ['ar' => 'م', 'en' => 's'],
            'description' => ['ar' => 'و', 'en' => 'd'],
        ]);

        return $this->makeProduct($key, ProductStatus::Published, ProductType::Course, $publishedAt, courseId: $course->id);
    }
}
