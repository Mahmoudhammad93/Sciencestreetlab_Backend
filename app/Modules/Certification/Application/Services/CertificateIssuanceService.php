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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CertificateIssuanceService
{
    public function __construct(
        private readonly CertificateNumberGenerator $numberGenerator,
        private readonly CertificateTemplateRenderer $renderer,
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

        $existing = $this->existingCertificate($enrollment);
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

        $user = $enrollment->user;
        if ($user === null) {
            Log::error('certificate.issuance_missing_user', [
                'enrollment_id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
            ]);

            return null;
        }

        try {
            $certificate = DB::transaction(function () use ($enrollment, $template, $user): Certificate {
                $locked = $this->existingCertificate($enrollment, lock: true);
                if ($locked) {
                    return $locked;
                }

                $certificateNumber = $this->numberGenerator->next();
                $verificationCode = Str::random(32);
                $issuedAt = now();
                $locale = app()->getLocale();

                $variables = [
                    'student_name' => (string) $user->name,
                    'course_name' => (string) $enrollment->course->getTranslation('title', $locale),
                    'completion_date' => $issuedAt->format('d/m/Y'),
                    'issue_date' => $issuedAt->format('d/m/Y'),
                    'certificate_number' => $certificateNumber,
                    'verification_url' => url('/certificates/verify/'.$verificationCode),
                ];

                $metadata = $this->renderer->buildIssuanceSnapshot($template, $variables);
                $metadata['course_title'] = $variables['course_name'];

                return Certificate::query()->create([
                    'certificate_number' => $certificateNumber,
                    'verification_code' => $verificationCode,
                    'user_id' => $enrollment->user_id,
                    'course_id' => $enrollment->course_id,
                    'enrollment_id' => $enrollment->id,
                    'template_id' => $template->id,
                    'issued_at' => $issuedAt,
                    'metadata' => $metadata,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $certificate = $this->existingCertificate($enrollment);
            if ($certificate === null) {
                throw new DomainException('Certificate uniqueness conflict could not be resolved.');
            }

            return $certificate;
        }

        GenerateCertificatePdfJob::dispatch($certificate);

        event(new CertificateIssued($certificate));

        return $certificate;
    }

    private function existingCertificate(Enrollment $enrollment, bool $lock = false): ?Certificate
    {
        $query = Certificate::query()->where(function ($q) use ($enrollment): void {
            $q->where('enrollment_id', $enrollment->id)
                ->orWhere(function ($inner) use ($enrollment): void {
                    $inner->where('user_id', $enrollment->user_id)
                        ->where('course_id', $enrollment->course_id);
                });
        });

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
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

        return null;
    }
}
