<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
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

        $launchUrl = app(InteractiveActivityPackageService::class)->signedLaunchUrl($this->resource);
        $user = $request->user();
        $learner = $user
            ? app(\App\Modules\Assessment\Application\Services\InteractiveActivityService::class)
                ->learnerState($user, $this->resource)
            : null;

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
            'launch_url' => $launchUrl,
            'html_url' => $launchUrl,
            'embed' => [
                'mode' => 'iframe',
                'sandbox' => 'allow-scripts',
                'src' => $launchUrl,
            ],
            'protocol' => 'postMessage',
            'sandbox' => 'allow-scripts',
            'topic_id' => $this->topic_id,
            'is_required' => (bool) $this->is_required,
            'max_attempts' => $this->max_attempts,
            'learner' => $learner,
        ];
    }
}
