<?php

declare(strict_types=1);

namespace App\Modules\Competition\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\ReviewAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionReview extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'submission_id', 'reviewer_id', 'action', 'score', 'notes', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => ReviewAction::class,
            'created_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(CompetitionSubmission::class, 'submission_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
