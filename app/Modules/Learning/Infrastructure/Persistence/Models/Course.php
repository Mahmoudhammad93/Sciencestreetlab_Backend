<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Domain\Enums\AccessType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class Course extends Model
{
    use HasTranslations, SoftDeletes;

    /** @var list<string> */
    public array $translatable = ['title', 'description', 'short_description'];

    protected $fillable = [
        'uuid', 'slug', 'product_id', 'access_type', 'prerequisite_course_id',
        'estimated_hours', 'certificate_template_id', 'is_published', 'sort_order',
        'published_at', 'image_url', 'title', 'description', 'short_description',
    ];

    protected static function booted(): void
    {
        static::creating(function (Course $course): void {
            if (empty($course->uuid)) {
                $course->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'access_type' => AccessType::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
        ];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
