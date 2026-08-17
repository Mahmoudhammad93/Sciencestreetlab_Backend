<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    protected $fillable = [
        'quiz_id', 'user_id', 'enrollment_id', 'attempt_number', 'status',
        'score', 'max_score', 'percentage', 'passed',
        'started_at', 'submitted_at', 'graded_at', 'time_spent_seconds',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAttemptAnswer::class);
    }

    public function frozenQuestions(): HasMany
    {
        return $this->hasMany(QuizAttemptQuestion::class)->orderBy('sort_order');
    }
}
