<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Listeners;

use App\Modules\Certification\Application\Services\CertificateIssuanceService;
use App\Modules\Learning\Domain\Events\CourseCompleted;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downstream of Learning course completion. Must not roll back Learning progress
 * and must not leak CertificateTemplate ModelNotFoundException to the student API.
 */
final class IssueCertificateOnCourseCompleted implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly CertificateIssuanceService $issuance,
    ) {}

    public function handle(CourseCompleted $event): void
    {
        try {
            $enrollment = $event->enrollment->fresh(['course', 'user']);

            if (! $enrollment || ! $enrollment->course) {
                return;
            }

            if ($enrollment->course_plan_id !== null && ! $enrollment->grant_certificate) {
                return;
            }

            $this->issuance->issue($enrollment);
        } catch (Throwable $e) {
            Log::error('certificate.issuance_failed_after_course_completion', [
                'enrollment_id' => $event->enrollment->id ?? null,
                'course_id' => $event->enrollment->course_id ?? null,
                'user_id' => $event->enrollment->user_id ?? null,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
