<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Listeners;

use App\Modules\Certification\Application\Services\CertificateIssuanceService;
use App\Modules\Learning\Domain\Events\CourseCompleted;

final class IssueCertificateOnCourseCompleted
{
    public function __construct(
        private readonly CertificateIssuanceService $issuance,
    ) {}

    public function handle(CourseCompleted $event): void
    {
        $enrollment = $event->enrollment->fresh(['course', 'user']);

        if (! $enrollment || ! $enrollment->course) {
            return;
        }

        $this->issuance->issue($enrollment);
    }
}
