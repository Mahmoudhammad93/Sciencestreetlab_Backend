<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivityAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InteractiveActivityAttempt */
final class InteractiveActivityAttemptResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $includeLaunch = false,
        private readonly ?array $launch = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InteractiveActivityAttempt $attempt */
        $attempt = $this->resource;

        $data = [
            'attempt_id' => $attempt->id,
            'id' => $attempt->id,
            'uuid' => $attempt->uuid,
            'activity_id' => $attempt->activity_id,
            'lesson_id' => $attempt->lesson_id,
            'quiz_attempt_id' => $attempt->quiz_attempt_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status->value,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'max_score' => $attempt->max_score !== null ? (float) $attempt->max_score : null,
            'score_verified' => (bool) $attempt->score_verified,
            'progress' => is_array($attempt->metadata['progress'] ?? null)
                ? $attempt->metadata['progress']
                : null,
        ];

        if ($this->includeLaunch && $this->launch) {
            $data['launch'] = $this->launch;
            $data['launch_url'] = $this->launch['url'] ?? null;
        }

        return $data;
    }
}
