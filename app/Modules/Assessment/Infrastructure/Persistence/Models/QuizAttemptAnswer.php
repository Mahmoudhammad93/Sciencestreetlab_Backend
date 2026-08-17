<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttemptAnswer extends Model
{
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

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
