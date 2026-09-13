<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;

final class EnrollmentVerificationService
{
    public function __construct(
        private readonly CoursePlanAccessService $planAccess,
    ) {}

    /**
     * @return array{verified: true, student_name: string, course_name: string, status: string, enrolled_at: string|null}|array{verified: false}
     */
    public function verify(string $token): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['verified' => false];
        }

        $enrollment = Enrollment::query()
            ->with(['user', 'course', 'orderItem.order.payment'])
            ->where('enrollment_verification_token', $token)
            ->first();

        if (! $enrollment || ! $this->isVerifiable($enrollment)) {
            return ['verified' => false];
        }

        return [
            'verified' => true,
            'student_name' => $enrollment->user?->name ?: 'Student',
            'course_name' => $this->courseName($enrollment->course),
            'status' => $enrollment->status->value,
            'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
        ];
    }

    private function isVerifiable(Enrollment $enrollment): bool
    {
        if (! $this->planAccess->isEnrollmentActive($enrollment)) {
            return false;
        }

        $order = $enrollment->orderItem?->order;
        if ($order && in_array($order->status, [OrderStatus::Cancelled->value, OrderStatus::Refunded->value], true)) {
            return false;
        }

        if ($order?->payment?->status === PaymentStatus::Refunded->value) {
            return false;
        }

        return true;
    }

    private function courseName(?Course $course): string
    {
        if (! $course) {
            return 'Course';
        }

        foreach ([app()->getLocale(), 'en', 'ar'] as $locale) {
            $name = $course->getTranslation('title', $locale, false);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return 'Course';
    }
}
