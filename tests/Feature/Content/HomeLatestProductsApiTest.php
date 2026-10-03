<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomeLatestProductsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_latest_published_products_with_limit(): void
    {
        $this->makeProduct('draft-1', ProductStatus::Draft, now()->subDays(1));

        $older = $this->makeProduct('older', ProductStatus::Published, now()->subDays(5));
        $newest = $this->makeProduct('newest', ProductStatus::Published, now()->subDay());
        $middle = $this->makeProduct('middle', ProductStatus::Published, now()->subDays(2));

        $response = $this->getJson('/api/v1/home/latest-products?limit=2')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newest->id, $middle->id], $ids);
        $this->assertNotContains($older->id, $ids);
    }

    public function test_limit_is_clamped(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeProduct("p-{$i}", ProductStatus::Published, now()->subMinutes($i));
        }

        $this->getJson('/api/v1/home/latest-products?limit=0')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/home/latest-products?limit=99')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    private function makeProduct(string $key, ProductStatus $status, $publishedAt): Product
    {
        return Product::query()->create([
            'sku' => 'HOME-'.$key,
            'slug' => 'home-'.$key,
            'type' => ProductType::Kit,
            'status' => $status,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => $publishedAt,
            'name' => ['en' => $key, 'ar' => $key],
        ]);
    }
}
