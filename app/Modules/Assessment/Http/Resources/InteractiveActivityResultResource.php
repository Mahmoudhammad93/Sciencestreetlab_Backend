<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivityAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InteractiveActivityAttempt */
final class InteractiveActivityResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InteractiveActivityAttempt $attempt */
        $attempt = $this->resource;

        return [
            'attempt_id' => $attempt->id,
            'activity_id' => $attempt->activity_id,
            'status' => $attempt->status->value,
            'client_score' => $attempt->client_score !== null ? (float) $attempt->client_score : null,
            'verified_score' => $attempt->verified_score !== null ? (float) $attempt->verified_score : null,
            'max_score' => $attempt->max_score !== null ? (float) $attempt->max_score : null,
            'percentage' => $attempt->percentage !== null ? (float) $attempt->percentage : null,
            'score_verified' => (bool) $attempt->score_verified,
            'time_spent_seconds' => $attempt->time_spent_seconds,
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'progress' => is_array($attempt->metadata['progress'] ?? null)
                ? $attempt->metadata['progress']
                : null,
            'result' => $attempt->result,
            'note' => $attempt->score_verified
                ? 'verified_score is authoritative'
                : 'client_score is unverified; not authoritative for certificates unless explicitly allowed',
        ];
    }
}
