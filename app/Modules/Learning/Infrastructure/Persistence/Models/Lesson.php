<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use App\Modules\Learning\Domain\Enums\LessonType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Translatable\HasTranslations;

class Lesson extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'content'];

    protected $fillable = [
        'course_id', 'slug', 'lesson_type', 'sort_order', 'is_published', 'video_duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'lesson_type' => LessonType::class,
            'is_published' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderBy('sort_order');
    }

    public function quizzes(): MorphMany
    {
        return $this->morphMany(\App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz::class, 'quizable');
    }
}
