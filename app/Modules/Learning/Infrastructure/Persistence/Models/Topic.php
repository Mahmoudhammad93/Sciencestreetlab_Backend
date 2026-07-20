<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class Topic extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'content'];

    protected $fillable = [
        'lesson_id', 'slug', 'sort_order', 'content_type', 'video_url',
        'video_provider', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
