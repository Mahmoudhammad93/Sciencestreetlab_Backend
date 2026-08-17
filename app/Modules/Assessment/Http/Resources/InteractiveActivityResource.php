<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InteractiveActivity */
final class InteractiveActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'lesson_id' => $this->lesson_id,
            'activity_type' => $this->activity_type,
            'difficulty' => $this->difficulty?->value ?? $this->difficulty,
            'points' => (float) $this->points,
            'estimated_time_seconds' => $this->estimated_time_seconds,
            'version' => $this->version,
            'status' => $this->status?->value ?? $this->status,
            'title' => $this->getTranslation('title', $locale),
            'description' => $this->getTranslation('description', $locale) ?: null,
            'instructions' => $this->getTranslation('instructions', $locale) ?: null,
            'has_package' => filled($this->activity_package_path),
            'protocol' => 'postMessage',
            'sandbox' => 'allow-scripts',
        ];
    }
}
