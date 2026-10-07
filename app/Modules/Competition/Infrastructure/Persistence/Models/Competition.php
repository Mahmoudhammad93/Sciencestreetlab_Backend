<?php

declare(strict_types=1);

namespace App\Modules\Competition\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class Competition extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'description', 'rules'];

    protected $fillable = [
        'uuid', 'slug', 'prerequisite_course_id', 'required_photos',
        'photos_per_sample', 'max_photos_per_sample', 'starts_at', 'ends_at',
        'status', 'prize_description', 'prize_amount', 'rules_version',
        'title', 'description', 'rules',
    ];

    protected static function booted(): void
    {
        static::creating(function (Competition $competition): void {
            if (empty($competition->uuid)) {
                $competition->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'prize_amount' => 'decimal:2',
        ];
    }

    public function prerequisiteCourse(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Learning\Infrastructure\Persistence\Models\Course::class, 'prerequisite_course_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CompetitionParticipant::class);
    }

    public function winners(): HasMany
    {
        return $this->hasMany(CompetitionWinner::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && now()->between($this->starts_at, $this->ends_at);
    }

    public function maxSampleNumber(): int
    {
        return (int) ceil($this->required_photos / max(1, $this->photos_per_sample));
    }

    /**
     * Authoritative upload constraints for API clients.
     *
     * @return array{
     *     min_sample_number: int,
     *     max_sample_number: int,
     *     max_photos_per_sample: int,
     *     required_photos: int,
     *     photos_per_sample: int,
     *     allowed_mimes: list<string>,
     *     allowed_extensions: list<string>,
     *     max_file_kb: int,
     *     description_max: int,
     *     description_required: bool
     * }
     */
    public function uploadRules(): array
    {
        return [
            'min_sample_number' => 1,
            'max_sample_number' => $this->maxSampleNumber(),
            'max_photos_per_sample' => (int) $this->max_photos_per_sample,
            'required_photos' => (int) $this->required_photos,
            'photos_per_sample' => (int) $this->photos_per_sample,
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_file_kb' => 10240,
            'description_max' => 2000,
            'description_required' => false,
        ];
    }
}
