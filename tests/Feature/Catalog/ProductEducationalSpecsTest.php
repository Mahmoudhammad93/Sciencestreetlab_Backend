<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
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
            'key_benefits' => [
                'en' => ['Hands-on labs', 'Curriculum aligned'],
                'ar' => ['تجارب عملية', 'متوافق مع المنهج'],
            ],
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
        $product->addMedia(UploadedFile::fake()->image('design-lab.jpg'))
            ->toMediaCollection('design_lab');

        $response = $this->getJson('/api/v1/products/edu-spec-kit', [
            'Accept-Language' => 'en',
        ])->assertOk();

        $response->assertJsonPath('data.difficulty_level', 'Intermediate')
            ->assertJsonPath('data.target_age', '10-14')
            ->assertJsonPath('data.difficultyLevel', 'Intermediate')
            ->assertJsonPath('data.targetAge', '10-14')
            ->assertJsonPath('data.key_benefits.0', 'Hands-on labs')
            ->assertJsonPath('data.keyBenefits.1', 'Curriculum aligned')
            ->assertJsonPath('data.scientific_concepts.1', 'Cells')
            ->assertJsonPath('data.curriculum_alignment.0.grade_level', 'Grade 6')
            ->assertJsonPath('data.curriculumAlignment.1.lesson_name', 'Microscopy')
            ->assertJsonPath('data.related_course.slug', 'intro-to-science')
            ->assertJsonPath('data.relatedCourse.title', $related->getTranslation('title', 'en'))
            ->assertJsonPath('data.design_lab_description', 'Design a lens experiment.')
            ->assertJsonPath('data.design_lab.text', 'Design a lens experiment.')
            ->assertJsonPath('data.type', 'bundle');

        $this->assertNotEmpty($response->json('data.gallery'));
        $this->assertNotEmpty($response->json('data.concept_images'));
        $this->assertNotEmpty($response->json('data.conceptImages'));
        $this->assertNotEmpty($response->json('data.design_lab_image'));
        $this->assertNotEmpty($response->json('data.design_lab.image'));
        $this->assertSame(
            'Design a lens experiment.',
            $response->json('data.related_course.design_lab_description')
        );

        $ar = $this->getJson('/api/v1/products/edu-spec-kit', [
            'Accept-Language' => 'ar',
        ])->assertOk();

        $ar->assertJsonPath('data.scientific_concepts.0', 'التكبير')
            ->assertJsonPath('data.key_benefits.0', 'تجارب عملية')
            ->assertJsonPath('data.keyBenefits.1', 'متوافق مع المنهج')
            ->assertJsonPath('data.design_lab_description', 'صمم تجربة عدسة.')
            ->assertJsonPath('data.design_lab.text', 'صمم تجربة عدسة.')
            ->assertJsonPath('data.creative_lab_description', 'ابتكر قصة عالم مصغر.')
            ->assertJsonPath('data.curriculum_alignment.0.grade_level', 'الصف السادس')
            ->assertJsonPath('data.curriculum_alignment.0.lesson_name', 'الخلايا')
            ->assertJsonPath('data.related_course.design_lab_description', 'صمم تجربة عدسة.');

        $this->assertSame(
            $response->json('data.design_lab_image'),
            $ar->json('data.design_lab_image'),
            'Design Lab image URL must be identical for EN and AR'
        );
        $this->assertSame(
            $response->json('data.design_lab.image'),
            $ar->json('data.design_lab.image')
        );
    }

    public function test_design_lab_image_can_be_attached_and_replaced(): void
    {
        Storage::fake('public');

        $product = Product::query()->create([
            'sku' => 'DL-IMG-001',
            'slug' => 'design-lab-image-kit',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 150,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Design Lab Kit', 'ar' => 'حقيبة مختبر التصميم'],
            'design_lab_description' => [
                'en' => 'Shared image product.',
                'ar' => 'منتج بصورة مشتركة.',
            ],
        ]);

        $product->addMedia(UploadedFile::fake()->image('design-a.jpg'))
            ->toMediaCollection('design_lab');

        $firstUrl = $product->fresh()->design_lab_image;
        $this->assertNotNull($firstUrl);
        $this->assertSame(1, $product->getMedia('design_lab')->count());

        $product->addMedia(UploadedFile::fake()->image('design-b.jpg'))
            ->toMediaCollection('design_lab');

        $product = $product->fresh();
        $this->assertSame(1, $product->getMedia('design_lab')->count());
        $this->assertNotNull($product->design_lab_image);
        $this->assertNotSame($firstUrl, $product->design_lab_image);
    }

    public function test_missing_design_lab_image_does_not_break_product_details(): void
    {
        $product = Product::query()->create([
            'sku' => 'DL-NO-IMG',
            'slug' => 'no-design-lab-image',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'No Image Kit', 'ar' => 'بدون صورة'],
            'design_lab_description' => [
                'en' => 'Text only design lab.',
                'ar' => 'مختبر تصميم نص فقط.',
            ],
        ]);

        $response = $this->getJson('/api/v1/products/'.$product->slug, [
            'Accept-Language' => 'en',
        ])->assertOk();

        $response->assertJsonPath('data.design_lab_description', 'Text only design lab.')
            ->assertJsonPath('data.design_lab.text', 'Text only design lab.')
            ->assertJsonPath('data.design_lab.image', null)
            ->assertJsonPath('data.design_lab_image', null)
            ->assertJsonPath('data.designLabImage', null);
    }

    public function test_existing_design_lab_text_is_preserved_when_image_added(): void
    {
        Storage::fake('public');

        $product = Product::query()->create([
            'sku' => 'DL-PRESERVE',
            'slug' => 'preserve-design-lab-text',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 120,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Preserve Kit', 'ar' => 'حفظ'],
            'design_lab_description' => [
                'en' => 'Keep this English copy.',
                'ar' => 'احتفظ بهذا النص العربي.',
            ],
        ]);

        $product->addMedia(UploadedFile::fake()->image('later.jpg'))
            ->toMediaCollection('design_lab');

        $fresh = $product->fresh();
        $this->assertSame('Keep this English copy.', $fresh->getTranslation('design_lab_description', 'en'));
        $this->assertSame('احتفظ بهذا النص العربي.', $fresh->getTranslation('design_lab_description', 'ar'));
        $this->assertNotNull($fresh->design_lab_image);
    }

    public function test_order_manager_cannot_edit_products_by_permission(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $viewer = User::factory()->create();
        $viewer->assignRole('order_manager');

        $editor = User::factory()->create();
        $editor->assignRole('content_manager');

        $this->assertFalse($viewer->can('products.edit'));
        $this->assertTrue($editor->can('products.edit'));
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
            ->assertJsonPath('data.design_lab_description', 'English only design lab')
            ->assertJsonPath('data.design_lab.text', 'English only design lab')
            ->assertJsonPath('data.design_lab.image', null);
    }

    public function test_legacy_flat_key_benefits_still_expose_via_api(): void
    {
        $product = Product::query()->create([
            'sku' => 'EDU-LEGACY-BEN',
            'slug' => 'edu-legacy-benefits',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Legacy Benefits', 'ar' => 'فوائد قديمة'],
        ]);

        // Simulate pre-localization storage shape.
        \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)->update([
            'key_benefits' => json_encode(['Hands-on', 'Aligned'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->getJson('/api/v1/products/edu-legacy-benefits', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.key_benefits.0', 'Hands-on')
            ->assertJsonPath('data.keyBenefits.1', 'Aligned');
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
