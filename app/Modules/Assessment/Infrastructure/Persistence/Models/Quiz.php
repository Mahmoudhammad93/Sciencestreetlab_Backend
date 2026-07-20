<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class Quiz extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'instructions'];

    protected $fillable = [
        'uuid', 'quizable_type', 'quizable_id', 'passing_score', 'max_attempts',
        'time_limit_seconds', 'shuffle_questions', 'is_required',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quiz $quiz): void {
            if (empty($quiz->uuid)) {
                $quiz->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'passing_score' => 'decimal:2',
            'shuffle_questions' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    public function quizable(): MorphTo
    {
        return $this->morphTo();
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
