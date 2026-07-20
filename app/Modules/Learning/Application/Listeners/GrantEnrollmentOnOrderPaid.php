<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Listeners;

use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;

final class GrantEnrollmentOnOrderPaid
{
    public function __construct(
        private readonly EnrollUserService $enrollUserService,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing(['items.product', 'user']);

        foreach ($order->items as $item) {
            $courseId = $item->metadata['course_id'] ?? null;

            if (! $courseId) {
                $courseId = $item->product?->course_id;
            }

            if (! $courseId) {
                continue;
            }

            $course = Course::query()->find($courseId);

            if (! $course) {
                continue;
            }

            $this->enrollUserService->enroll($order->user, $course, $item->id);
        }
    }
}
