<?php

declare(strict_types=1);

namespace App\Modules\Learning\Infrastructure\Persistence\Models;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

class Topic extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'content'];

    protected $fillable = [
    'lesson_id',
    'slug',
    'sort_order',
    'content_type',
    'video_url',
    'video_provider',
    'bunny_video_id',
    'video_status',
    'video_progress',
    'is_published',
    'title',
    'content',
];

    protected function casts(): array
{
    return [
        'is_published' => 'boolean',
        'video_progress' => 'integer',
    ];
}
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function interactiveActivity(): HasOne
    {
        return $this->hasOne(InteractiveActivity::class);
    }

    public function isInteractive(): bool
    {
        return $this->content_type === 'interactive';
    }
}
