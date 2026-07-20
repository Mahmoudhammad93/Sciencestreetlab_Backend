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
use Illuminate\Support\Str;

final class CertificateIssuanceService
{
    public function __construct(
        private readonly CertificateNumberGenerator $numberGenerator,
    ) {}

    public function issue(Enrollment $enrollment): Certificate
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

    private function resolveTemplate(Enrollment $enrollment): CertificateTemplate
    {
        if ($enrollment->course->certificate_template_id) {
            $template = CertificateTemplate::query()
                ->where('id', $enrollment->course->certificate_template_id)
                ->where('is_active', true)
                ->first();

            if ($template) {
                return $template;
            }
        }

        return CertificateTemplate::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();
    }
}
