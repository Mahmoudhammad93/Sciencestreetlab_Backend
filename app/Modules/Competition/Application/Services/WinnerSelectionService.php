<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionWinner;
use DomainException;
use Illuminate\Support\Facades\DB;

final class WinnerSelectionService
{
    public function selectWinner(User $admin, Competition $competition, CompetitionParticipant $participant): CompetitionWinner
    {
        if ($participant->competition_id !== $competition->id) {
            throw new DomainException('participant_mismatch');
        }

        if ($participant->status !== ParticipantStatus::Shortlisted) {
            throw new DomainException('participant_not_shortlisted');
        }

        return DB::transaction(function () use ($admin, $competition, $participant): CompetitionWinner {
            $winner = CompetitionWinner::query()->updateOrCreate(
                [
                    'competition_id' => $competition->id,
                    'participant_id' => $participant->id,
                ],
                ['rank' => 1]
            );

            $participant->update(['status' => ParticipantStatus::Winner]);
            $competition->update(['status' => 'completed']);

            return $winner;
        });
    }

    public function verifyWinner(User $admin, CompetitionWinner $winner): CompetitionWinner
    {
        $winner->update([
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        return $winner->fresh();
    }
}
