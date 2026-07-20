<?php

declare(strict_types=1);

namespace App\Modules\Competition\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionWinner extends Model
{
    protected $fillable = [
        'competition_id', 'participant_id', 'rank',
        'verified_at', 'verified_by', 'prize_claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'prize_claimed_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CompetitionParticipant::class, 'participant_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
