<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class InteractiveActivity extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title', 'description', 'instructions'];

    protected $fillable = [
        'uuid',
        'lesson_id',
        'status',
        'activity_type',
        'difficulty',
        'points',
        'estimated_time_seconds',
        'version',
        'activity_package_path',
        'entry_file',
        'activity_config',
        'title',
        'description',
        'instructions',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (InteractiveActivity $activity): void {
            if (empty($activity->uuid)) {
                $activity->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InteractiveActivityStatus::class,
            'difficulty' => QuestionDifficulty::class,
            'activity_config' => 'array',
            'points' => 'float',
            'version' => 'integer',
            'estimated_time_seconds' => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(InteractiveActivityAttempt::class, 'activity_id');
    }

    public function quizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'quiz_interactive_activity')
            ->withPivot(['sort_order', 'points'])
            ->withTimestamps();
    }

    public function isPublished(): bool
    {
        return $this->status === InteractiveActivityStatus::Published;
    }
}
