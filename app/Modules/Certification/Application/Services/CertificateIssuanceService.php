<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Services;

use App\Modules\Certification\Domain\Events\CertificateIssued;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Modules\Certification\Jobs\GenerateCertificatePdfJob;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CertificateIssuanceService
{
    public function __construct(
        private readonly CertificateNumberGenerator $numberGenerator,
    ) {}

    /**
     * Issue a certificate when the enrollment is completed and a template is configured.
     * Returns null when issuance is skipped (already issued, or missing/inactive template).
     * Never throws ModelNotFoundException for optional template lookup.
     */
    public function issue(Enrollment $enrollment): ?Certificate
    {
        $enrollment->loadMissing('course', 'user');

        if ($enrollment->status !== EnrollmentStatus::Completed) {
            throw new DomainException('Course not completed.');
        }

        $existing = Certificate::query()
            ->where('enrollment_id', $enrollment->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $template = $this->resolveTemplate($enrollment);

        if ($template === null) {
            Log::warning('certificate.template_missing', [
                'enrollment_id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
                'course_id' => $enrollment->course_id,
                'course_certificate_template_id' => $enrollment->course?->certificate_template_id,
                'message' => 'Skipping certificate issuance: no active CertificateTemplate available.',
            ]);

            return null;
        }

        $certificate = Certificate::query()->create([
            'certificate_number' => $this->numberGenerator->next(),
            'verification_code' => Str::random(32),
            'user_id' => $enrollment->user_id,
            'course_id' => $enrollment->course_id,
            'enrollment_id' => $enrollment->id,
            'template_id' => $template->id,
            'issued_at' => now(),
            'metadata' => [
                'student_name' => $enrollment->user->name,
                'course_title' => $enrollment->course->getTranslation('title', app()->getLocale()),
            ],
        ]);

        GenerateCertificatePdfJob::dispatch($certificate);

        event(new CertificateIssued($certificate));

        return $certificate;
    }

    private function resolveTemplate(Enrollment $enrollment): ?CertificateTemplate
    {
        $courseTemplateId = $enrollment->course?->certificate_template_id;

        if ($courseTemplateId) {
            $template = CertificateTemplate::query()
                ->where('id', $courseTemplateId)
                ->where('is_active', true)
                ->first();

            if ($template) {
                return $template;
            }

            Log::warning('certificate.course_template_inactive_or_missing', [
                'course_id' => $enrollment->course_id,
                'certificate_template_id' => $courseTemplateId,
            ]);
        }

        return CertificateTemplate::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
