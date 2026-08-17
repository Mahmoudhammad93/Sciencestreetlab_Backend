<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityAttemptStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InteractiveActivityAttempt extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'activity_id',
        'lesson_id',
        'enrollment_id',
        'quiz_attempt_id',
        'attempt_number',
        'status',
        'client_score',
        'verified_score',
        'max_score',
        'percentage',
        'score_verified',
        'time_spent_seconds',
        'result',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (InteractiveActivityAttempt $attempt): void {
            if (empty($attempt->uuid)) {
                $attempt->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InteractiveActivityAttemptStatus::class,
            'client_score' => 'float',
            'verified_score' => 'float',
            'max_score' => 'float',
            'percentage' => 'float',
            'score_verified' => 'boolean',
            'result' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(InteractiveActivity::class, 'activity_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function quizAttempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class);
    }
}
