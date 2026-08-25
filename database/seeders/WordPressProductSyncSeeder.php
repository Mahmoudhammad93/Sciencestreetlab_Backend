<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Syncs purchasable products from frontend wp-mirror.json so /item/{slug} and cart work for all courses.
 */
final class WordPressProductSyncSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('../frontend/src/data/wp-mirror.json');
        if (! is_file($path)) {
            $this->command?->warn('wp-mirror.json not found — skip WordPress product sync.');

            return;
        }

        $json = json_decode((string) file_get_contents($path), true);
        $items = $json['products'] ?? [];

        $courseBySlug = Course::query()->pluck('id', 'slug');

        foreach ($items as $item) {
            $slug = (string) ($item['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $name = (string) ($item['name'] ?? $slug);
            $price = (float) ($item['price'] ?? 0);
            $wpId = (int) ($item['id'] ?? 0);
            $isCourse = str_starts_with($name, 'كورس') || str_starts_with($slug, 'كورس');

            $existing = Product::query()->where('slug', $slug)->first();

            $sku = $existing?->sku ?? ('SS-WP-'.($wpId > 0 ? $wpId : Str::upper(Str::substr(md5($slug), 0, 8))));

            $courseId = $existing?->course_id;
            if ($courseId === null && in_array($slug, ['كورس-الميكروسكوب', 'science-street-microscope', 'science-street-microscope-2'], true)) {
                $courseId = $courseBySlug['microscope-course'] ?? null;
            }
            if ($courseId === null && $slug === 'manual-power-generator') {
                $courseId = $courseBySlug['electricity-basics'] ?? null;
            }
            if ($courseId === null && in_array($slug, ['gear-truck', 'شاحنة-التروس', 'كورس-شاحنة-التروس'], true)) {
                $courseId = $courseBySlug['gear-mechanics'] ?? null;
            }

            Product::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'sku' => $sku,
                    'type' => $isCourse ? ProductType::Course : ProductType::Kit,
                    'status' => ProductStatus::Published,
                    'price' => $price > 0 ? $price : ($existing?->price ?? 100),
                    'compare_price' => (float) ($item['regular_price'] ?? $price),
                    'currency' => 'EGP',
                    'stock_quantity' => ($item['in_stock'] ?? true) ? 999 : 0,
                    'manage_stock' => false,
                    'course_id' => $courseId,
                    'published_at' => now(),
                    'name' => ['ar' => $name, 'en' => $name],
                    'short_description' => [
                        'ar' => $isCourse ? 'كورس رقمي من شارع العلوم' : 'منتج من متجر شارع العلوم',
                        'en' => $isCourse ? 'Digital course from Science Street' : 'Science Street shop product',
                    ],
                ]
            );
        }

        $this->command?->info('Synced '.count($items).' WordPress catalog products.');
    }
}
