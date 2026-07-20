<?php

declare(strict_types=1);

namespace App\Modules\Competition\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CompetitionSubmission extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'uuid', 'participant_id', 'sample_number', 'photo_index', 'status',
        'description', 'scientific_notes', 'rejection_reason',
        'submitted_at', 'reviewed_at', 'reviewed_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (CompetitionSubmission $submission): void {
            if (empty($submission->uuid)) {
                $submission->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CompetitionParticipant::class, 'participant_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SubmissionReview::class, 'submission_id');
    }
}
