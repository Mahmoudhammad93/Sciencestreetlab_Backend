<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use App\Modules\Catalog\Domain\Enums\ProductReviewStatus;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\SocialCommerce\Infrastructure\Persistence\Models\SalesChannelProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;

class Product extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia, SoftDeletes;

    /** @var list<string> */
    public array $translatable = [
        'name',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'scientific_concepts',
        'design_lab_description',
        'creative_lab_description',
    ];

    /** @var list<string> */
    protected $appends = ['image', 'gallery_urls', 'concept_image_urls'];

    protected $fillable = [
        'uuid', 'sku', 'slug', 'type', 'status', 'price', 'compare_price',
        'currency', 'stock_quantity', 'manage_stock', 'is_featured',
        'average_rating', 'review_count', 'course_id', 'course_plan_id', 'category_id', 'sort_order', 'published_at',
        'name', 'short_description', 'description', 'meta_title', 'meta_description',
        'difficulty_level', 'target_age', 'key_benefits', 'scientific_concepts',
        'design_lab_description', 'creative_lab_description', 'related_course_id',
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
            'key_benefits' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function coursePlan(): BelongsTo
    {
        return $this->belongsTo(CoursePlan::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function relatedCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'related_course_id');
    }

    public function curriculumAlignments(): HasMany
    {
        return $this->hasMany(ProductCurriculumAlignment::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', ProductReviewStatus::Approved->value);
    }

    public function salesChannelProducts(): HasMany
    {
        return $this->hasMany(SalesChannelProduct::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

        $this->addMediaCollection('concept_images')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    }

    public function getImageAttribute(): ?string
    {
        $url = $this->getFirstMediaUrl('image');

        return $url !== '' ? $url : null;
    }

    /**
     * @return list<string>
     */
    public function getGalleryUrlsAttribute(): array
    {
        return $this->getMedia('gallery')
            ->map(fn ($media) => $media->getUrl())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function getConceptImageUrlsAttribute(): array
    {
        return $this->getMedia('concept_images')
            ->map(fn ($media) => $media->getUrl())
            ->filter()
            ->values()
            ->all();
    }
}
