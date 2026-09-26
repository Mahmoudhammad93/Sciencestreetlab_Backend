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
            'scientific_concepts' => ['en' => ['Magnification', 'Cells'], 'ar' => ['التكبير', 'الخلايا']],
            'design_lab_description' => [
                'en' => 'Design a lens experiment.',
                'ar' => 'صمم تجربة عدسة.',
            ],
            'creative_lab_description' => [
                'en' => 'Create a micro-world story.',
                'ar' => 'ابتكر قصة عالم مصغر.',
            ],
            'related_course_id' => $related->id,
        ]);

        app(ProductEducationalSpecSyncService::class)->syncCurriculumAlignments($product, [
            [
                'grade_level' => ['en' => 'Grade 6', 'ar' => 'الصف السادس'],
                'lesson_name' => ['en' => 'Cells', 'ar' => 'الخلايا'],
            ],
            [
                'grade_level' => ['en' => 'Grade 7', 'ar' => 'الصف السابع'],
                'lesson_name' => ['en' => 'Microscopy', 'ar' => 'الميكروسكوب'],
            ],
        ]);

        $product->addMedia(UploadedFile::fake()->image('gallery-1.jpg'))
            ->toMediaCollection('gallery');
        $product->addMedia(UploadedFile::fake()->image('concept-1.jpg'))
            ->toMediaCollection('concept_images');

        $response = $this->getJson('/api/v1/products/edu-spec-kit', [
            'Accept-Language' => 'en',
        ])->assertOk();

        $response->assertJsonPath('data.difficulty_level', 'Intermediate')
            ->assertJsonPath('data.target_age', '10-14')
            ->assertJsonPath('data.difficultyLevel', 'Intermediate')
            ->assertJsonPath('data.targetAge', '10-14')
            ->assertJsonPath('data.key_benefits.0', 'Hands-on labs')
            ->assertJsonPath('data.scientific_concepts.1', 'Cells')
            ->assertJsonPath('data.curriculum_alignment.0.grade_level', 'Grade 6')
            ->assertJsonPath('data.curriculumAlignment.1.lesson_name', 'Microscopy')
            ->assertJsonPath('data.related_course.slug', 'intro-to-science')
            ->assertJsonPath('data.relatedCourse.title', $related->getTranslation('title', 'en'))
            ->assertJsonPath('data.design_lab_description', 'Design a lens experiment.')
            ->assertJsonPath('data.type', 'bundle');

        $this->assertNotEmpty($response->json('data.gallery'));
        $this->assertNotEmpty($response->json('data.concept_images'));
        $this->assertNotEmpty($response->json('data.conceptImages'));
        $this->assertSame(
            'Design a lens experiment.',
            $response->json('data.related_course.design_lab_description')
        );

        $ar = $this->getJson('/api/v1/products/edu-spec-kit', [
            'Accept-Language' => 'ar',
        ])->assertOk();

        $ar->assertJsonPath('data.scientific_concepts.0', 'التكبير')
            ->assertJsonPath('data.design_lab_description', 'صمم تجربة عدسة.')
            ->assertJsonPath('data.creative_lab_description', 'ابتكر قصة عالم مصغر.')
            ->assertJsonPath('data.curriculum_alignment.0.grade_level', 'الصف السادس')
            ->assertJsonPath('data.curriculum_alignment.0.lesson_name', 'الخلايا')
            ->assertJsonPath('data.related_course.design_lab_description', 'صمم تجربة عدسة.');
    }

    public function test_missing_translation_falls_back_to_available_locale(): void
    {
        $product = Product::query()->create([
            'sku' => 'EDU-FALLBACK-1',
            'slug' => 'edu-fallback',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Fallback Kit', 'ar' => 'حقيبة'],
            'scientific_concepts' => ['en' => ['Only English']],
            'design_lab_description' => ['en' => 'English only design lab'],
        ]);

        $this->getJson('/api/v1/products/edu-fallback', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.scientific_concepts.0', 'Only English')
            ->assertJsonPath('data.design_lab_description', 'English only design lab');
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
