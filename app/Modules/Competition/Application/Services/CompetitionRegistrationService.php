<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Services;

use App\Models\User;
use App\Modules\Competition\Domain\Enums\ParticipantStatus;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use DomainException;

final class CompetitionRegistrationService
{
    public function __construct(
        private readonly CompetitionEligibilityService $eligibility,
    ) {}

    public function register(User $user, Competition $competition): CompetitionParticipant
    {
        $check = $this->eligibility->canParticipate($user, $competition);

        if (! $check['eligible']) {
            throw new DomainException($check['reason'] ?? 'not_eligible');
        }

        $existing = CompetitionParticipant::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return CompetitionParticipant::query()->create([
            'competition_id' => $competition->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now(),
        ]);
    }
}
