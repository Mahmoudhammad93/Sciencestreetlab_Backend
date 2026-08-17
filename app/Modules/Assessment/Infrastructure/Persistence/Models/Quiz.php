<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'selection_mode', 'selection_config',
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
            'selection_mode' => QuizSelectionMode::class,
            'selection_config' => 'array',
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

    public function questionBanks(): BelongsToMany
    {
        return $this->belongsToMany(QuestionBank::class, 'quiz_question_bank');
    }

    public function interactiveActivities(): BelongsToMany
    {
        return $this->belongsToMany(InteractiveActivity::class, 'quiz_interactive_activity')
            ->withPivot(['sort_order', 'points'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function isGenerated(): bool
    {
        return $this->selection_mode === QuizSelectionMode::Generated;
    }
}
