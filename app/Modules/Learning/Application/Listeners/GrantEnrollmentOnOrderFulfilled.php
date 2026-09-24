<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Listeners;

use App\Modules\Commerce\Domain\Events\OrderFulfilled;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;

/**
 * Grants course / course-plan access when an order is fulfilled.
 * For online/digital orders this follows payment; for Bosta kits this follows DELIVERED.
 */
final class GrantEnrollmentOnOrderFulfilled
{
    public function __construct(
        private readonly EnrollUserService $enrollUserService,
    ) {}

    public function handle(OrderFulfilled $event): void
    {
        $order = $event->order->loadMissing(['items.product.coursePlan', 'items.product', 'user']);

        foreach ($order->items as $item) {
            $product = $item->product;
            $courseId = $item->metadata['course_id'] ?? $product?->course_id;
            $planId = $item->metadata['course_plan_id'] ?? $product?->course_plan_id;

            if (! $courseId) {
                continue;
            }

            $course = Course::query()->find($courseId);

            if (! $course) {
                continue;
            }

            $plan = null;
            if ($planId) {
                $plan = CoursePlan::query()
                    ->whereKey($planId)
                    ->where('course_id', $course->id)
                    ->where('is_active', true)
                    ->first();
            }

            $this->enrollUserService->enroll($order->user, $course, $item->id, $plan);
        }
    }
}
