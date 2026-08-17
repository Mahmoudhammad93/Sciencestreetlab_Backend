<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class Question extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['body', 'explanation'];

    protected $fillable = [
        'uuid', 'quiz_id', 'question_bank_id', 'question_type', 'difficulty', 'status',
        'points', 'sort_order', 'body', 'explanation',
        'interactive_type', 'interactive_path', 'interactive_config', 'interactive_activity_id', 'answer_key',
    ];

    protected $hidden = [
        'answer_key',
        'interactive_path',
    ];

    protected static function booted(): void
    {
        static::creating(function (Question $question): void {
            if (empty($question->uuid)) {
                $question->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'question_type' => QuestionType::class,
            'difficulty' => QuestionDifficulty::class,
            'status' => QuestionStatus::class,
            'points' => 'decimal:2',
            'interactive_config' => 'array',
            'answer_key' => 'array',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(QuestionTag::class, 'question_tag');
    }

    public function interactiveActivity(): BelongsTo
    {
        return $this->belongsTo(InteractiveActivity::class, 'interactive_activity_id');
    }

    public function isPublished(): bool
    {
        return $this->status === QuestionStatus::Published;
    }
}
