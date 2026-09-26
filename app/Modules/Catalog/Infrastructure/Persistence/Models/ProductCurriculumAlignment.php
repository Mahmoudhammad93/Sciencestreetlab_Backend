<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class ProductCurriculumAlignment extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['grade_level', 'lesson_name'];

    protected $fillable = [
        'product_id',
        'grade_level',
        'lesson_name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
