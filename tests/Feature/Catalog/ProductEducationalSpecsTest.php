<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\ProductEducationalSpecSyncService;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductEducationalSpecsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_educational_specifications_save_and_return_from_api(): void
    {
        Storage::fake('public');
        $this->seed();

        $related = Course::query()->where('slug', 'intro-to-science')->firstOrFail();

        $product = Product::query()->create([
            'sku' => 'EDU-SPEC-001',
            'slug' => 'edu-spec-kit',
            'type' => ProductType::Bundle,
            'status' => ProductStatus::Published,
            'price' => 499,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Microscope Package', 'ar' => 'باقة المجهر'],
            'difficulty_level' => 'Intermediate',
            'target_age' => '10-14',
            'key_benefits' => ['Hands-on labs', 'Curriculum aligned'],
            'scientific_concepts' => ['Magnification', 'Cells'],
            'design_lab_description' => 'Design a lens experiment.',
            'creative_lab_description' => 'Create a micro-world story.',
            'related_course_id' => $related->id,
        ]);

        app(ProductEducationalSpecSyncService::class)->syncCurriculumAlignments($product, [
            ['grade_level' => 'Grade 6', 'lesson_name' => 'Cells'],
            ['grade_level' => 'Grade 7', 'lesson_name' => 'Microscopy'],
        ]);

        $product->addMedia(UploadedFile::fake()->image('gallery-1.jpg'))
            ->toMediaCollection('gallery');
        $product->addMedia(UploadedFile::fake()->image('concept-1.jpg'))
            ->toMediaCollection('concept_images');

        $response = $this->getJson('/api/v1/products/edu-spec-kit')->assertOk();

        $response->assertJsonPath('data.difficulty_level', 'Intermediate')
            ->assertJsonPath('data.target_age', '10-14')
            ->assertJsonPath('data.difficultyLevel', 'Intermediate')
            ->assertJsonPath('data.targetAge', '10-14')
            ->assertJsonPath('data.key_benefits.0', 'Hands-on labs')
            ->assertJsonPath('data.scientific_concepts.1', 'Cells')
            ->assertJsonPath('data.curriculum_alignment.0.grade_level', 'Grade 6')
            ->assertJsonPath('data.curriculumAlignment.1.lesson_name', 'Microscopy')
            ->assertJsonPath('data.related_course.slug', 'intro-to-science')
            ->assertJsonPath('data.relatedCourse.title', $related->getTranslation('title', app()->getLocale()))
            ->assertJsonPath('data.design_lab_description', 'Design a lens experiment.')
            ->assertJsonPath('data.type', 'bundle');

        $this->assertNotEmpty($response->json('data.gallery'));
        $this->assertNotEmpty($response->json('data.concept_images'));
        $this->assertNotEmpty($response->json('data.conceptImages'));
        $this->assertSame(
            'Design a lens experiment.',
            $response->json('data.related_course.design_lab_description')
        );
    }

    public function test_existing_product_image_collection_still_works(): void
    {
        Storage::fake('public');

        $product = Product::query()->create([
            'sku' => 'IMG-001',
            'slug' => 'image-kit',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Image Kit', 'ar' => 'حقيبة'],
        ]);

        $product->addMedia(UploadedFile::fake()->image('main.jpg'))
            ->toMediaCollection('image');

        $this->getJson('/api/v1/products/image-kit')
            ->assertOk()
            ->assertJsonPath('data.slug', 'image-kit');

        $this->assertNotNull($product->fresh()->image);
    }
}
