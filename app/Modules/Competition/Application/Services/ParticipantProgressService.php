<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;

final class ParticipantProgressService
{
    public function recalculate(CompetitionParticipant $participant): CompetitionParticipant
    {
        $counts = $participant->submissions()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $approved = (int) ($counts[SubmissionStatus::Approved->value] ?? 0);
        $pending = (int) ($counts[SubmissionStatus::Pending->value] ?? 0);
        $rejected = (int) ($counts[SubmissionStatus::Rejected->value] ?? 0)
            + (int) ($counts[SubmissionStatus::RevisionRequested->value] ?? 0);

        $participant->update([
            'approved_count' => $approved,
            'pending_count' => $pending,
            'rejected_count' => $rejected,
        ]);

        $competition = $participant->competition;
        if ($approved > 0 && $participant->status === ParticipantStatus::Registered) {
            $participant->update(['status' => ParticipantStatus::Active]);
        }

        if ($approved >= $competition->required_photos && ! in_array($participant->fresh()->status, [ParticipantStatus::Shortlisted, ParticipantStatus::Winner], true)) {
            $participant->update(['status' => ParticipantStatus::Active]);
        }

        return $participant->fresh();
    }
}
