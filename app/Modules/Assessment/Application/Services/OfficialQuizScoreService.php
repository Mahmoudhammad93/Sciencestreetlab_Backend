<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizOfficialScore;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Support\Facades\DB;

final class OfficialQuizScoreService
{
    /**
     * Mark the first successfully submitted attempt as official (idempotent).
     */
    public function markOfficialOnSubmit(QuizAttempt $attempt): void
    {
        if ($attempt->submitted_at === null) {
            return;
        }

        DB::transaction(function () use ($attempt): void {
            $existing = QuizOfficialScore::query()
                ->where('enrollment_id', $attempt->enrollment_id)
                ->where('quiz_id', $attempt->quiz_id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return;
            }

            QuizOfficialScore::query()->create([
                'enrollment_id' => $attempt->enrollment_id,
                'quiz_id' => $attempt->quiz_id,
                'quiz_attempt_id' => $attempt->id,
            ]);

            QuizAttempt::query()
                ->whereKey($attempt->id)
                ->update(['is_official' => true]);
        });
    }

    public function officialAttempt(User $user, Quiz $quiz, Enrollment $enrollment): ?QuizAttempt
    {
        $record = QuizOfficialScore::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('quiz_id', $quiz->id)
            ->first();

        if ($record === null) {
            return null;
        }

        return QuizAttempt::query()
            ->whereKey($record->quiz_attempt_id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * @return array{
     *   official_score: float|null,
     *   official_attempt_number: int|null,
     *   official_attempt_id: int|null,
     *   official_passed: bool|null
     * }
     */
    public function officialPayload(User $user, Quiz $quiz, Enrollment $enrollment): array
    {
        $attempt = $this->officialAttempt($user, $quiz, $enrollment);

        if ($attempt === null) {
            return [
                'official_score' => null,
                'official_attempt_number' => null,
                'official_attempt_id' => null,
                'official_passed' => null,
            ];
        }

        return [
            'official_score' => $attempt->percentage !== null ? (float) $attempt->percentage : null,
            'official_attempt_number' => (int) $attempt->attempt_number,
            'official_attempt_id' => $attempt->id,
            'official_passed' => $attempt->passed,
        ];
    }

    public function hasPassedOfficially(User $user, Quiz $quiz, Enrollment $enrollment): bool
    {
        $attempt = $this->officialAttempt($user, $quiz, $enrollment);

        return $attempt !== null && $attempt->passed === true;
    }
}
