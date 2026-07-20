<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Domain\Enums\ReviewAction;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Domain\Events\CompetitionSubmissionApproved;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use App\Modules\Competition\Infrastructure\Persistence\Models\SubmissionReview;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SubmissionReviewService
{
    public function __construct(
        private readonly ParticipantProgressService $progress,
    ) {}

    public function approve(User $reviewer, CompetitionSubmission $submission, ?string $notes = null, ?float $score = null): CompetitionSubmission
    {
        return $this->review($reviewer, $submission, ReviewAction::Approve, SubmissionStatus::Approved, $notes, $score);
    }

    public function reject(User $reviewer, CompetitionSubmission $submission, string $reason, ?string $notes = null): CompetitionSubmission
    {
        if ($reason === '') {
            throw new DomainException('rejection_reason_required');
        }

        return DB::transaction(function () use ($reviewer, $submission, $reason, $notes): CompetitionSubmission {
            $updated = $this->review(
                $reviewer,
                $submission,
                ReviewAction::Reject,
                SubmissionStatus::Rejected,
                $notes
            );

            $updated->update(['rejection_reason' => $reason]);

            return $updated->fresh();
        });
    }

    public function requestRevision(User $reviewer, CompetitionSubmission $submission, string $notes): CompetitionSubmission
    {
        if ($notes === '') {
            throw new DomainException('revision_notes_required');
        }

        return $this->review(
            $reviewer,
            $submission,
            ReviewAction::RequestRevision,
            SubmissionStatus::RevisionRequested,
            $notes
        );
    }

    public function shortlistParticipant(User $reviewer, CompetitionParticipant $participant): CompetitionParticipant
    {
        if ($participant->approved_count < $participant->competition->required_photos) {
            throw new DomainException('insufficient_approved_photos');
        }

        SubmissionReview::query()->create([
            'submission_id' => $participant->submissions()->latest('id')->value('id'),
            'reviewer_id' => $reviewer->id,
            'action' => ReviewAction::Shortlist,
            'notes' => 'Participant shortlisted',
            'created_at' => now(),
        ]);

        $participant->update([
            'status' => ParticipantStatus::Shortlisted,
            'shortlisted_at' => now(),
        ]);

        return $participant->fresh();
    }

    private function review(
        User $reviewer,
        CompetitionSubmission $submission,
        ReviewAction $action,
        SubmissionStatus $newStatus,
        ?string $notes = null,
        ?float $score = null,
    ): CompetitionSubmission {
        if ($submission->status !== SubmissionStatus::Pending) {
            throw new DomainException('submission_not_pending');
        }

        return DB::transaction(function () use ($reviewer, $submission, $action, $newStatus, $notes, $score): CompetitionSubmission {
            $submission->update([
                'status' => $newStatus,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
            ]);

            SubmissionReview::query()->create([
                'submission_id' => $submission->id,
                'reviewer_id' => $reviewer->id,
                'action' => $action,
                'score' => $score,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $participant = $this->progress->recalculate($submission->participant->fresh());

            if ($newStatus === SubmissionStatus::Approved) {
                event(new CompetitionSubmissionApproved($submission->fresh(['participant.user'])));
            }

            return $submission->fresh(['participant', 'media']);
        });
    }
}
