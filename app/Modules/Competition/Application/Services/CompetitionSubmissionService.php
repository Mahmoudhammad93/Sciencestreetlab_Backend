<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class CompetitionSubmissionService
{
    public function __construct(
        private readonly ParticipantProgressService $progress,
    ) {}

    /**
     * @param  array{sample_number: int, photo_index?: int, description?: string, scientific_notes?: string}  $data
     */
    public function submit(
        User $user,
        Competition $competition,
        UploadedFile $photo,
        array $data,
    ): CompetitionSubmission {
        if (! $competition->isActive()) {
            throw new DomainException('competition_not_active');
        }

        $participant = CompetitionParticipant::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $participant) {
            throw new DomainException('not_registered');
        }

        if ($participant->status->value === 'disqualified') {
            throw new DomainException('participant_disqualified');
        }

        $sampleNumber = (int) $data['sample_number'];
        $photoIndex = (int) ($data['photo_index'] ?? 1);

        $this->validateSlot($competition, $sampleNumber, $photoIndex);

        $this->validatePhoto($photo);

        return DB::transaction(function () use ($participant, $photo, $data, $sampleNumber, $photoIndex): CompetitionSubmission {
            $existing = CompetitionSubmission::query()
                ->where('participant_id', $participant->id)
                ->where('sample_number', $sampleNumber)
                ->where('photo_index', $photoIndex)
                ->first();

            if ($existing && ! in_array($existing->status, [SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected], true)) {
                throw new DomainException('slot_already_submitted');
            }

            if ($existing) {
                $existing->clearMediaCollection('photo');
                $existing->update([
                    'status' => SubmissionStatus::Pending,
                    'description' => $data['description'] ?? $existing->description,
                    'scientific_notes' => $data['scientific_notes'] ?? $existing->scientific_notes,
                    'rejection_reason' => null,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ]);
                $submission = $existing;
            } else {
                $submission = CompetitionSubmission::query()->create([
                    'participant_id' => $participant->id,
                    'sample_number' => $sampleNumber,
                    'photo_index' => $photoIndex,
                    'status' => SubmissionStatus::Pending,
                    'description' => $data['description'] ?? null,
                    'scientific_notes' => $data['scientific_notes'] ?? null,
                    'submitted_at' => now(),
                ]);
            }

            $submission->addMedia($photo)->toMediaCollection('photo');

            $this->progress->recalculate($participant->fresh());

            return $submission->fresh();
        });
    }

    public function updateMetadata(User $user, CompetitionSubmission $submission, array $data): CompetitionSubmission
    {
        $this->assertOwnership($user, $submission);

        $submission->update([
            'description' => $data['description'] ?? $submission->description,
            'scientific_notes' => $data['scientific_notes'] ?? $submission->scientific_notes,
        ]);

        return $submission->fresh();
    }

    private function validateSlot(Competition $competition, int $sampleNumber, int $photoIndex): void
    {
        if ($sampleNumber < 1 || $sampleNumber > $competition->maxSampleNumber()) {
            throw new DomainException('invalid_sample_number');
        }

        if ($photoIndex < 1 || $photoIndex > $competition->max_photos_per_sample) {
            throw new DomainException('invalid_photo_index');
        }
    }

    private function validatePhoto(UploadedFile $photo): void
    {
        if (! in_array($photo->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new DomainException('invalid_photo_type');
        }

        if ($photo->getSize() > 10 * 1024 * 1024) {
            throw new DomainException('photo_too_large');
        }
    }

    private function assertOwnership(User $user, CompetitionSubmission $submission): void
    {
        $submission->loadMissing('participant');

        if ($submission->participant->user_id !== $user->id) {
            throw new DomainException('forbidden');
        }
    }
}
