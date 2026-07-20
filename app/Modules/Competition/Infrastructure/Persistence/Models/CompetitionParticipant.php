<?php

declare(strict_types=1);

namespace App\Modules\Competition\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CompetitionParticipant extends Model
{
    protected $fillable = [
        'competition_id', 'user_id', 'status',
        'approved_count', 'pending_count', 'rejected_count',
        'registered_at', 'shortlisted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'registered_at' => 'datetime',
            'shortlisted_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CompetitionSubmission::class, 'participant_id');
    }

    public function winner(): HasOne
    {
        return $this->hasOne(CompetitionWinner::class, 'participant_id');
    }
}
