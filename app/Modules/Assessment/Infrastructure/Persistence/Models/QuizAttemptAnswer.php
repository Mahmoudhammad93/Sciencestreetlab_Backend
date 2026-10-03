<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class QuizAttemptAnswer extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const MEDIA_COLLECTION = 'answer_images';

    protected $fillable = [
        'quiz_attempt_id', 'question_id', 'selected_option_ids', 'text_answer',
        'numeric_answer', 'matching_answer', 'ordering_answer', 'interactive_answer',
        'client_result', 'server_result', 'needs_manual_review',
        'is_correct', 'points_awarded',
    ];

    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'matching_answer' => 'array',
            'ordering_answer' => 'array',
            'interactive_answer' => 'array',
            'client_result' => 'array',
            'server_result' => 'array',
            'needs_manual_review' => 'boolean',
            'is_correct' => 'boolean',
            'points_awarded' => 'decimal:2',
            'numeric_answer' => 'decimal:4',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->useDisk('local')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Light review thumbnail only — keep original for microscope detail.
        $this->addMediaConversion('review')
            ->width(1600)
            ->height(1600)
            ->keepOriginalImageFormat()
            ->nonQueued();
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
