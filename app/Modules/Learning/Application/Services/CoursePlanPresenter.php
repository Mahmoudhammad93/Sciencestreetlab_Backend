<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;

final class CoursePlanPresenter
{
    public function __construct(
        private readonly CoursePlanEntitlementSyncService $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(CoursePlan $plan): array
    {
        $locale = app()->getLocale();
        $selection = $this->entitlements->selectionFromPlan($plan);

        return [
            'id' => $plan->id,
            'course_id' => $plan->course_id,
            'name' => $plan->getTranslation('name', $locale),
            'description' => $plan->getTranslation('description', $locale) ?: null,
            'price' => number_format((float) $plan->price, 2, '.', ''),
            'currency' => $plan->currency,
            'is_lifetime' => (bool) $plan->is_lifetime,
            'duration_days' => $plan->duration_days,
            'max_quiz_attempts' => $plan->max_quiz_attempts,
            'grant_certificate' => (bool) $plan->grant_certificate,
            'is_free' => $plan->isFree(),
            // Free plans enroll directly; paid plans must be bought through this product.
            'product_id' => $plan->isFree() ? null : $plan->product?->id,
            'lesson_ids' => $selection['lesson_ids'],
            'topic_ids' => $selection['topic_ids'],
            'quiz_ids' => $selection['quiz_ids'],
            'interactive_activity_ids' => $selection['interactive_activity_ids'],
            'entitlements' => $selection,
        ];
    }
}
