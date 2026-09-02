<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;

final class CoursePlanPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(CoursePlan $plan): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $plan->id,
            'name' => $plan->getTranslation('name', $locale),
            'description' => $plan->getTranslation('description', $locale) ?: null,
            'price' => number_format((float) $plan->price, 2, '.', ''),
            'currency' => $plan->currency,
            'is_lifetime' => (bool) $plan->is_lifetime,
            'duration_days' => $plan->duration_days,
            'max_quiz_attempts' => $plan->max_quiz_attempts,
            'grant_certificate' => (bool) $plan->grant_certificate,
        ];
    }
}
