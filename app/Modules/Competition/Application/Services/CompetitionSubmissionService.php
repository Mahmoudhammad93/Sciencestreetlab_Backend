<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\SubmissionStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CompetitionSubmissionService
{
    public function __construct(
        private readonly ParticipantProgressService $progress,
    ) {}

    /**
     * @param  array{sample_number: int, photo_index?: int|null, description?: string, scientific_notes?: string}  $data
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

        if (! array_key_exists('sample_number', $data) || $data['sample_number'] === null || $data['sample_number'] === '') {
            throw new DomainException('invalid_sample_number');
        }

        $sampleNumber = (int) $data['sample_number'];

        // Historical migrated rows may have null slots; never treat null as a valid new slot.
        if ($sampleNumber < 1) {
            throw new DomainException('invalid_sample_number');
        }

        $explicitPhotoIndex = $this->explicitPhotoIndex($data);
        if ($explicitPhotoIndex !== null && $explicitPhotoIndex < 1) {
            throw new DomainException('invalid_photo_index');
        }

        if ($explicitPhotoIndex !== null) {
            $this->validateSlot($competition, $sampleNumber, $explicitPhotoIndex);
        } elseif ($sampleNumber > $competition->maxSampleNumber()) {
            throw new DomainException('invalid_sample_number');
        }

        $this->validatePhoto($photo);

        try {
            return DB::transaction(function () use ($participant, $competition, $photo, $data, $sampleNumber, $explicitPhotoIndex): CompetitionSubmission {
                $locked = CompetitionParticipant::query()
                    ->whereKey($participant->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $photoIndex = $explicitPhotoIndex ?? $this->nextPhotoIndex($locked, $competition, $sampleNumber);

                $existing = CompetitionSubmission::query()
                    ->where('participant_id', $locked->id)
                    ->where('sample_number', $sampleNumber)
                    ->where('photo_index', $photoIndex)
                    ->lockForUpdate()
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
                        'participant_id' => $locked->id,
                        'sample_number' => $sampleNumber,
                        'photo_index' => $photoIndex,
                        'status' => SubmissionStatus::Pending,
                        'description' => $data['description'] ?? null,
                        'scientific_notes' => $data['scientific_notes'] ?? null,
                        'submitted_at' => now(),
                    ]);
                }

                $submission->addMedia($photo)
                    ->usingFileName($this->safePhotoFilename($photo, $submission))
                    ->toMediaCollection('photo');

                $this->progress->recalculate($locked->fresh());

                return $submission->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('slot_already_submitted');
        }
    }

    /**
     * Replace a rejected / revision-requested photo on the same submission row.
     * Preserves sample_number and photo_index, including historical null slots.
     *
     * @param  array{description?: string, scientific_notes?: string}  $data
     */
    public function replacePhoto(
        User $user,
        CompetitionSubmission $submission,
        UploadedFile $photo,
        array $data = [],
    ): CompetitionSubmission {
        $this->assertOwnership($user, $submission);
        $this->validatePhoto($photo);

        $submission->loadMissing('participant.competition');
        $competition = $submission->participant->competition;

        if (! $competition->isActive()) {
            throw new DomainException('competition_not_active');
        }

        if ($submission->participant->status->value === 'disqualified') {
            throw new DomainException('participant_disqualified');
        }

        return DB::transaction(function () use ($submission, $photo, $data): CompetitionSubmission {
            $locked = CompetitionSubmission::query()
                ->whereKey($submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedParticipant = CompetitionParticipant::query()
                ->whereKey($locked->participant_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [SubmissionStatus::RevisionRequested, SubmissionStatus::Rejected], true)) {
                throw new DomainException('slot_already_submitted');
            }

            $locked->clearMediaCollection('photo');
            $locked->update([
                'status' => SubmissionStatus::Pending,
                'description' => $data['description'] ?? $locked->description,
                'scientific_notes' => $data['scientific_notes'] ?? $locked->scientific_notes,
                'rejection_reason' => null,
                'submitted_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);

            $locked->addMedia($photo)
                ->usingFileName($this->safePhotoFilename($photo, $locked))
                ->toMediaCollection('photo');

            $this->progress->recalculate($lockedParticipant->fresh());

            return $locked->fresh(['participant.competition', 'media', 'reviews']);
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

    /**
     * @param  array{photo_index?: int|null}  $data
     */
    private function explicitPhotoIndex(array $data): ?int
    {
        if (! array_key_exists('photo_index', $data) || $data['photo_index'] === null || $data['photo_index'] === '') {
            return null;
        }

        return (int) $data['photo_index'];
    }

    private function nextPhotoIndex(
        CompetitionParticipant $participant,
        Competition $competition,
        int $sampleNumber,
    ): int {
        $occupied = CompetitionSubmission::query()
            ->where('participant_id', $participant->id)
            ->where('sample_number', $sampleNumber)
            ->whereNotNull('photo_index')
            ->whereNotIn('status', [
                SubmissionStatus::RevisionRequested->value,
                SubmissionStatus::Rejected->value,
            ])
            ->pluck('photo_index')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $max = (int) $competition->max_photos_per_sample;
        for ($index = 1; $index <= $max; $index++) {
            if (! in_array($index, $occupied, true)) {
                return $index;
            }
        }

        throw new DomainException('sample_photos_full');
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

    private function safePhotoFilename(UploadedFile $photo, CompetitionSubmission $submission): string
    {
        $extension = match ($photo->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return sprintf(
            'competition-%s-%s.%s',
            $submission->uuid,
            Str::lower(Str::random(8)),
            $extension,
        );
    }

    private function assertOwnership(User $user, CompetitionSubmission $submission): void
    {
        $submission->loadMissing('participant');

        if ($submission->participant->user_id !== $user->id) {
            throw new DomainException('forbidden');
        }
    }
}
