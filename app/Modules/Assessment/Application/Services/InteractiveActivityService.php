<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityAttemptStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivityAttempt;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InteractiveActivityService
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly InteractiveActivityPackageService $packages,
    ) {}

    public function authorizeActivity(User $user, InteractiveActivity $activity): Enrollment
    {
        $activity->loadMissing('lesson.course');
        $lesson = $activity->lesson;
        if (! $lesson instanceof Lesson) {
            throw new DomainException('QUESTION_NOT_FOUND: Activity lesson missing.', 404);
        }

        $enrollment = $this->access->requireEnrollment($user, $lesson->course);

        if ($activity->status !== InteractiveActivityStatus::Published) {
            throw new DomainException('QUESTION_LOCKED: Activity is not published.', 403);
        }

        if (! $this->access->canAccessLesson($enrollment, $lesson)) {
            throw new DomainException('QUESTION_LOCKED: Lesson is locked.', 403);
        }

        return $enrollment;
    }

    /**
     * @return array<string, mixed>
     */
    public function launchPayload(User $user, InteractiveActivity $activity): array
    {
        $enrollment = $this->authorizeActivity($user, $activity);
        $url = $this->packages->signedLaunchUrl($activity);

        if (! $url) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Activity package not available.', 404);
        }

        return [
            'activity_id' => $activity->id,
            'uuid' => $activity->uuid,
            'version' => $activity->version,
            'url' => $url,
            'expires_at' => now()->addMinutes(60)->toIso8601String(),
            'sandbox' => 'allow-scripts',
            'protocol' => 'postMessage',
            'enrollment_id' => $enrollment->id,
            'post_message_events' => [
                'READY', 'STARTED', 'PROGRESS', 'CHALLENGE_STARTED', 'CHALLENGE_COMPLETED',
                'QUESTION_STARTED', 'ANSWER_SUBMITTED', 'QUESTION_COMPLETED',
                'ACTIVITY_COMPLETED', 'RETRY', 'ERROR',
            ],
        ];
    }

    public function startAttempt(
        User $user,
        InteractiveActivity $activity,
        ?int $quizAttemptId = null,
    ): InteractiveActivityAttempt {
        $enrollment = $this->authorizeActivity($user, $activity);

        $inProgress = InteractiveActivityAttempt::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->where('status', InteractiveActivityAttemptStatus::InProgress)
            ->latest('id')
            ->first();

        if ($inProgress) {
            return $inProgress->load('activity');
        }

        $number = InteractiveActivityAttempt::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->count() + 1;

        return InteractiveActivityAttempt::query()->create([
            'user_id' => $user->id,
            'activity_id' => $activity->id,
            'lesson_id' => $activity->lesson_id,
            'enrollment_id' => $enrollment->id,
            'quiz_attempt_id' => $quizAttemptId,
            'attempt_number' => $number,
            'status' => InteractiveActivityAttemptStatus::InProgress,
            'max_score' => (float) $activity->points,
            'started_at' => now(),
        ])->load('activity');
    }

    /**
     * Store in-progress challenge progress reported by the HTML activity (untrusted).
     *
     * @param  array<string, mixed>  $payload
     */
    public function submitProgress(InteractiveActivityAttempt $attempt, array $payload): InteractiveActivityAttempt
    {
        if ($attempt->status !== InteractiveActivityAttemptStatus::InProgress) {
            throw new DomainException('ATTEMPT_EXPIRED: Attempt is not in progress.', 422);
        }

        $completed = (int) ($payload['completed_challenges'] ?? 0);
        $total = max(1, (int) ($payload['total_challenges'] ?? 1));
        $percentage = isset($payload['percentage'])
            ? (float) $payload['percentage']
            : round(($completed / $total) * 100, 2);

        $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        $metadata['progress'] = [
            'completed_challenges' => $completed,
            'total_challenges' => $total,
            'percentage' => $percentage,
            'updated_at' => now()->toIso8601String(),
        ];

        $attempt->update(['metadata' => $metadata]);

        return $attempt->fresh(['activity']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitResult(InteractiveActivityAttempt $attempt, array $payload): InteractiveActivityAttempt
    {
        if ($attempt->status !== InteractiveActivityAttemptStatus::InProgress) {
            throw new DomainException('ATTEMPT_EXPIRED: Attempt is not in progress.', 422);
        }

        $activity = $attempt->activity()->firstOrFail();
        $nested = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $result = array_merge($payload, $nested);
        unset($result['result']);

        $clientScore = isset($result['score'])
            ? (float) $result['score']
            : (isset($payload['clientScore']) ? (float) $payload['clientScore'] : null);
        $maxScore = isset($result['max_score'])
            ? (float) $result['max_score']
            : (float) ($activity->points ?: 100);

        $expected = $activity->activity_config['expected'] ?? null;
        $verifiedScore = null;
        $scoreVerified = false;

        if (is_array($expected) && isset($result['answers']) && is_array($result['answers'])) {
            $verifiedScore = $this->verifyAgainstExpected($expected, $result['answers'], $maxScore);
            $scoreVerified = true;
        }

        $finalScore = $scoreVerified ? $verifiedScore : $clientScore;
        $percentage = $maxScore > 0 && $finalScore !== null
            ? round(($finalScore / $maxScore) * 100, 2)
            : (isset($result['percentage']) ? (float) $result['percentage'] : null);

        $completed = (bool) ($payload['completed'] ?? $result['completed'] ?? true);
        $challengesCompleted = $result['challenges_completed'] ?? null;
        $totalChallenges = $result['total_challenges'] ?? null;

        return DB::transaction(function () use (
            $attempt, $result, $clientScore, $verifiedScore, $scoreVerified,
            $maxScore, $percentage, $finalScore, $completed, $payload,
            $challengesCompleted, $totalChallenges
        ) {
            $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            $metadata['clientScore'] = $clientScore;
            if ($challengesCompleted !== null || $totalChallenges !== null) {
                $metadata['progress'] = [
                    'completed_challenges' => $challengesCompleted,
                    'total_challenges' => $totalChallenges,
                    'percentage' => $percentage,
                    'updated_at' => now()->toIso8601String(),
                ];
            }

            $attempt->update([
                'status' => $completed
                    ? InteractiveActivityAttemptStatus::Completed
                    : InteractiveActivityAttemptStatus::InProgress,
                'client_score' => $clientScore,
                'verified_score' => $verifiedScore,
                'max_score' => $maxScore,
                'percentage' => $percentage,
                'score_verified' => $scoreVerified,
                'time_spent_seconds' => isset($result['time_spent_seconds'])
                    ? (int) $result['time_spent_seconds']
                    : ($payload['time_spent_seconds'] ?? null),
                'result' => [
                    'client_reported' => $result,
                    'authoritative_score' => $scoreVerified ? $verifiedScore : null,
                    'note' => $scoreVerified
                        ? 'Score verified against activity_config.expected'
                        : 'Client-reported score stored as unverified; opaque HTML activity — platform does not reverse-engineer game logic.',
                ],
                'metadata' => $metadata,
                'completed_at' => $completed ? now() : null,
            ]);

            return $attempt->fresh(['activity']);
        });
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $answers
     */
    private function verifyAgainstExpected(array $expected, array $answers, float $maxScore): float
    {
        $total = count($expected);
        if ($total === 0) {
            return 0.0;
        }

        $correct = 0;
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $answers)) {
                continue;
            }
            if ($this->looseEqual($value, $answers[$key])) {
                $correct++;
            }
        }

        return round(($correct / $total) * $maxScore, 2);
    }

    private function looseEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            ksort($a);
            ksort($b);

            return $a == $b;
        }

        if (is_string($a) && is_string($b)) {
            return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        }

        return $a == $b;
    }
}
