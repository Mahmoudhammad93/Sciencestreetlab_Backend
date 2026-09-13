<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_can_be_created_updated_and_deleted(): void
    {
        $category = Category::query()->create([
            'slug' => 'lab-kits',
            'name' => ['en' => 'Lab kits', 'ar' => 'حقائب المختبر'],
            'description' => ['en' => 'Hands-on kits', 'ar' => 'حقائب عملية'],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'slug' => 'lab-kits',
            'is_active' => true,
        ]);

        $category->update([
            'name' => ['en' => 'Science kits', 'ar' => 'حقائب علمية'],
            'is_active' => false,
            'sort_order' => 5,
        ]);

        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame('Science kits', $category->fresh()->getTranslation('name', 'en'));
        $this->assertSame(5, $category->fresh()->sort_order);

        $product = $this->product($category, 'published-kit', ProductStatus::Published);
        $category->delete();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull($product->fresh()->category_id);
    }

    public function test_slug_must_be_unique(): void
    {
        Category::query()->create([
            'slug' => 'lab-kits',
            'name' => ['en' => 'Lab kits', 'ar' => 'حقائب'],
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Category::query()->create([
            'slug' => 'lab-kits',
            'name' => ['en' => 'Duplicate', 'ar' => 'مكرر'],
            'is_active' => true,
        ]);
    }

    public function test_product_belongs_to_category_and_public_api_exposes_it(): void
    {
        $category = Category::query()->create([
            'slug' => 'lab-kits',
            'name' => ['en' => 'Lab kits', 'ar' => 'حقائب المختبر'],
            'description' => ['en' => 'Kits', 'ar' => 'حقائب'],
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = $this->product($category, 'microscope-kit', ProductStatus::Published);

        $this->assertTrue($product->category->is($category));
        $this->assertTrue($category->products->contains($product));

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'lab-kits')
            ->assertJsonPath('data.0.is_active', true);

        $this->getJson('/api/v1/categories/lab-kits')
            ->assertOk()
            ->assertJsonPath('data.slug', 'lab-kits');

        $this->getJson('/api/v1/categories/lab-kits/products')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'microscope-kit')
            ->assertJsonPath('data.0.category.slug', 'lab-kits');

        $this->getJson('/api/v1/products/microscope-kit')
            ->assertOk()
            ->assertJsonPath('data.category.slug', 'lab-kits')
            ->assertJsonPath('data.category.id', $category->id);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.category.slug', 'lab-kits');
    }

    public function test_inactive_category_is_hidden_and_draft_products_are_omitted(): void
    {
        $inactive = Category::query()->create([
            'slug' => 'hidden-kits',
            'name' => ['en' => 'Hidden', 'ar' => 'مخفي'],
            'is_active' => false,
            'sort_order' => 1,
        ]);
        $active = Category::query()->create([
            'slug' => 'visible-kits',
            'name' => ['en' => 'Visible', 'ar' => 'ظاهر'],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $hiddenProduct = $this->product($inactive, 'hidden-product', ProductStatus::Published);
        $this->product($active, 'draft-product', ProductStatus::Draft);
        $this->product($active, 'published-product', ProductStatus::Published);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonMissing(['slug' => 'hidden-kits'])
            ->assertJsonPath('data.0.slug', 'visible-kits');

        $this->getJson('/api/v1/categories/hidden-kits')->assertNotFound();
        $this->getJson('/api/v1/categories/hidden-kits/products')->assertNotFound();

        $this->getJson('/api/v1/categories/visible-kits/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'published-product');

        $this->getJson('/api/v1/products/hidden-product')
            ->assertOk()
            ->assertJsonPath('data.category.slug', 'hidden-kits')
            ->assertJsonPath('data.category.is_active', false);

        $this->assertSame($inactive->id, $hiddenProduct->fresh()->category_id);
    }

    private function product(Category $category, string $slug, ProductStatus $status): Product
    {
        return Product::query()->create([
            'sku' => strtoupper($slug),
            'slug' => $slug,
            'type' => ProductType::Kit,
            'status' => $status,
            'price' => 100,
            'currency' => 'EGP',
            'category_id' => $category->id,
            'published_at' => $status === ProductStatus::Published ? now() : null,
            'name' => ['en' => $slug, 'ar' => $slug],
        ]);
    }
}
