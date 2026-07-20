<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Services;

use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

final class CertificatePdfGenerator
{
    public function generate(Certificate $certificate): string
    {
        $certificate->loadMissing(['user', 'course', 'template']);

        $pdf = Pdf::loadView('certificates.default', [
            'certificate' => $certificate,
            'studentName' => $certificate->user->name,
            'courseTitle' => $certificate->course->getTranslation('title', 'ar'),
            'issuedDate' => $certificate->issued_at->format('d/m/Y'),
            'certificateNumber' => $certificate->certificate_number,
            'verificationUrl' => url("/api/v1/certificates/verify/{$certificate->verification_code}"),
        ])->setPaper('a4', 'landscape');

        $path = "certificates/{$certificate->uuid}.pdf";
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }
}
