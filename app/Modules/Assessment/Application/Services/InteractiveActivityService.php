<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityAttemptStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivityAttempt;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CourseProgressService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use DomainException;
use Illuminate\Support\Facades\DB;

final class InteractiveActivityService
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly InteractiveActivityPackageService $packages,
        private readonly CourseProgressService $progress,
    ) {}

    public function authorizeActivity(User $user, InteractiveActivity $activity): Enrollment
    {
        $activity->loadMissing(['lesson.course', 'topic']);

        if ($activity->status !== InteractiveActivityStatus::Published) {
            throw new DomainException('QUESTION_LOCKED: Activity is not published.', 403);
        }

        if ($activity->topic && ! $activity->topic->is_published) {
            throw new DomainException('QUESTION_LOCKED: Interactive topic is not published.', 403);
        }

        $lesson = $activity->lesson;
        if ($lesson instanceof Lesson) {
            try {
                $enrollment = $this->access->requireEnrollment($user, $lesson->course);
                if ($this->access->canAccessInteractiveActivity($enrollment, $activity)) {
                    return $enrollment;
                }
            } catch (DomainException) {
                // Fall through to quiz-linked access (activities reused across quizzes).
            }
        }

        throw new DomainException('QUESTION_LOCKED: Activity is not available for this student.', 403);
    }

    /**
     * Frontend contract: UI renders these flags; it must not recompute them.
     *
     * @return array<string, mixed>
     */
    public function learnerState(User $user, InteractiveActivity $activity): array
    {
        $attempts = InteractiveActivityAttempt::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $active = $attempts->firstWhere('status', InteractiveActivityAttemptStatus::InProgress);
        $completed = $attempts->firstWhere('status', InteractiveActivityAttemptStatus::Completed);
        $used = $attempts->count();
        $max = $activity->max_attempts;
        $remaining = $max === null ? null : max(0, $max - $used);
        $canResume = $active !== null;
        $canStart = $activity->isPublished()
            && ! $canResume
            && ($max === null || $used < $max);

        $progress = is_array($active?->metadata['progress'] ?? null)
            ? $active->metadata['progress']
            : (is_array($completed?->metadata['progress'] ?? null) ? $completed->metadata['progress'] : null);

        return [
            'can_start' => $canStart,
            'can_resume' => $canResume,
            'can_complete' => $active !== null,
            'is_completed' => $completed !== null,
            'is_locked' => false,
            'is_required' => (bool) $activity->is_required,
            'attempts_used' => $used,
            'attempts_remaining' => $remaining,
            'max_attempts' => $max,
            'status' => $active?->status->value
                ?? ($completed ? 'completed' : 'not_started'),
            'progress_percent' => (float) ($progress['percentage'] ?? ($completed ? 100 : 0)),
            'active_attempt_id' => $active?->id,
            'latest_completed_attempt_id' => $completed?->id,
        ];
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
        unset($quizAttemptId);

        $inProgress = InteractiveActivityAttempt::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->where('status', InteractiveActivityAttemptStatus::InProgress)
            ->latest('id')
            ->first();

        if ($inProgress) {
            return $inProgress->load('activity');
        }

        $used = InteractiveActivityAttempt::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->count();

        if ($activity->max_attempts !== null && $used >= (int) $activity->max_attempts) {
            throw new DomainException('MAX_ATTEMPTS_REACHED: No interactive attempts remaining.', 422);
        }

        return InteractiveActivityAttempt::query()->create([
            'user_id' => $user->id,
            'activity_id' => $activity->id,
            'lesson_id' => $activity->lesson_id,
            'enrollment_id' => $enrollment->id,
            'quiz_attempt_id' => null,
            'attempt_number' => $used + 1,
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
        $total = (int) ($payload['total_challenges'] ?? 0);
        if ($completed < 0 || $total < 1 || $completed > $total) {
            throw new DomainException('VALIDATION_ERROR: Invalid challenge progress.', 422);
        }
        $percentage = isset($payload['percentage'])
            ? min(100.0, max(0.0, (float) $payload['percentage']))
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

        $serverSeconds = max(0, (int) $attempt->started_at?->diffInSeconds(now(), false));

        return DB::transaction(function () use (
            $attempt, $result, $clientScore, $verifiedScore, $scoreVerified,
            $maxScore, $percentage, $completed, $payload,
            $challengesCompleted, $totalChallenges, $activity, $serverSeconds
        ) {
            $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            $metadata['client_score'] = $clientScore;
            $metadata['client_time_spent_seconds'] = $result['time_spent_seconds'] ?? $payload['time_spent_seconds'] ?? null;
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
                'time_spent_seconds' => $serverSeconds,
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

            if ($completed && $attempt->enrollment_id && $activity->topic_id) {
                $enrollment = $attempt->enrollment ?: Enrollment::query()->find($attempt->enrollment_id);
                $topic = $activity->topic;
                if ($enrollment && $topic) {
                    $this->progress->recordTopicProgress($enrollment, $topic, [
                        'watch_progress_percent' => 100,
                        'completed' => true,
                        'watched_seconds' => $serverSeconds,
                    ]);
                }
            }

            return $attempt->fresh(['activity']);
        });
    }

    /**
     * Store a completed interactive-activity score.
     *
     * Percentage is always calculated here. A client-supplied percentage is ignored.
     * This path is independent of quiz official scoring.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordScoreAttempt(User $user, InteractiveActivity $activity, array $payload): InteractiveActivityAttempt
    {
        $enrollment = $this->authorizeActivity($user, $activity);

        $score = (float) $payload['score'];
        $maxScore = (float) ($payload['max_score'] ?? $payload['maxScore']);
        $duration = $payload['duration_seconds'] ?? $payload['durationSeconds'] ?? null;
        $durationSeconds = $duration === null || $duration === '' ? null : (int) $duration;

        if ($score < 0 || $maxScore <= 0 || $score > $maxScore) {
            throw new DomainException('VALIDATION_ERROR: Score must be between 0 and max score.', 422);
        }

        if ($durationSeconds !== null && $durationSeconds < 0) {
            throw new DomainException('VALIDATION_ERROR: Duration must be zero or greater.', 422);
        }

        $result = strtolower((string) ($payload['result'] ?? 'completed'));
        $status = match ($result) {
            'completed' => InteractiveActivityAttemptStatus::Completed,
            'abandoned' => InteractiveActivityAttemptStatus::Abandoned,
            default => throw new DomainException('VALIDATION_ERROR: Result must be completed or abandoned.', 422),
        };

        $percentage = round(($score / $maxScore) * 100, 2);

        return DB::transaction(function () use (
            $user, $activity, $enrollment, $payload, $score, $maxScore,
            $durationSeconds, $status, $percentage, $result
        ) {
            $used = InteractiveActivityAttempt::query()
                ->where('activity_id', $activity->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->count();

            if ($activity->max_attempts !== null && $used >= (int) $activity->max_attempts) {
                throw new DomainException('MAX_ATTEMPTS_REACHED: No interactive attempts remaining.', 422);
            }

            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $metadata['result'] = $result;
            $metadata['score_source'] = 'interactive_activity_score';
            if (array_key_exists('percentage', $payload)) {
                $metadata['ignored_client_percentage'] = $payload['percentage'];
            }

            $attempt = InteractiveActivityAttempt::query()->create([
                'user_id' => $user->id,
                'activity_id' => $activity->id,
                'lesson_id' => $activity->lesson_id,
                'enrollment_id' => $enrollment->id,
                'attempt_number' => $used + 1,
                'status' => $status,
                'client_score' => $score,
                'verified_score' => null,
                'max_score' => $maxScore,
                'percentage' => $percentage,
                'score_verified' => false,
                'time_spent_seconds' => $durationSeconds,
                'result' => [
                    'client_reported' => [
                        'score' => $score,
                        'max_score' => $maxScore,
                        'result' => $result,
                    ],
                    'authoritative_score' => null,
                    'note' => 'Interactive activity score stored separately from quiz official scoring. Percentage calculated by the server.',
                ],
                'metadata' => $metadata,
                'started_at' => now(),
                'completed_at' => $status === InteractiveActivityAttemptStatus::Completed ? now() : null,
            ]);

            if ($status === InteractiveActivityAttemptStatus::Completed && $activity->topic_id) {
                $topic = $activity->topic;
                if ($topic) {
                    $this->progress->recordTopicProgress($enrollment, $topic, [
                        'watch_progress_percent' => 100,
                        'completed' => true,
                        'watched_seconds' => $durationSeconds ?? 0,
                    ]);
                }
            }

            return $attempt->fresh(['activity']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function scoreSummary(User $user, InteractiveActivity $activity): array
    {
        $this->authorizeActivity($user, $activity);

        $attempts = InteractiveActivityAttempt::query()
            ->where('user_id', $user->id)
            ->where('activity_id', $activity->id)
            ->orderByDesc('id')
            ->get();

        $completed = $attempts
            ->where('status', InteractiveActivityAttemptStatus::Completed)
            ->sortByDesc(fn (InteractiveActivityAttempt $attempt): array => [
                $attempt->percentage ?? -1,
                $attempt->completed_at?->getTimestamp() ?? 0,
                $attempt->id,
            ]);

        $best = $completed->first();
        $latest = $attempts->sortByDesc(fn (InteractiveActivityAttempt $attempt): int => $attempt->completed_at?->getTimestamp() ?? $attempt->created_at?->getTimestamp() ?? $attempt->id)->first();

        return [
            'activity_id' => $activity->id,
            'best_score' => $best?->client_score !== null ? (float) $best->client_score : null,
            'max_score' => $best?->max_score !== null ? (float) $best->max_score : null,
            'percentage' => $best?->percentage !== null ? (float) $best->percentage : null,
            'attempts_count' => $attempts->count(),
            'latest_attempt' => $latest ? $this->attemptScorePayload($latest) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attemptHistory(User $user, InteractiveActivity $activity): array
    {
        $this->authorizeActivity($user, $activity);

        return InteractiveActivityAttempt::query()
            ->where('user_id', $user->id)
            ->where('activity_id', $activity->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (InteractiveActivityAttempt $attempt): array => $this->attemptScorePayload($attempt))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptScorePayload(InteractiveActivityAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'score' => $attempt->client_score !== null ? (float) $attempt->client_score : null,
            'max_score' => $attempt->max_score !== null ? (float) $attempt->max_score : null,
            'percentage' => $attempt->percentage !== null ? (float) $attempt->percentage : null,
            'duration_seconds' => $attempt->time_spent_seconds,
            'status' => $attempt->status->value,
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'created_at' => $attempt->created_at?->toIso8601String(),
        ];
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
