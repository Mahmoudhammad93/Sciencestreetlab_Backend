<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;

class Product extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia, SoftDeletes;

    /** @var list<string> */
    public array $translatable = ['name', 'short_description', 'description', 'meta_title', 'meta_description'];

    protected $fillable = [
        'uuid', 'sku', 'slug', 'type', 'status', 'price', 'compare_price',
        'currency', 'stock_quantity', 'manage_stock', 'is_featured',
        'average_rating', 'review_count', 'course_id', 'sort_order', 'published_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'manage_stock' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
