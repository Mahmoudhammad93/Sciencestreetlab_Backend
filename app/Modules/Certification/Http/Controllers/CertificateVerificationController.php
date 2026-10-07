<?php

declare(strict_types=1);

namespace App\Modules\Certification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

final class CertificateVerificationController extends Controller
{
    public function __invoke(string $code): View|Response
    {
        $certificate = Certificate::query()
            ->where('verification_code', $code)
            ->with(['user:id,name', 'course:id,title'])
            ->first();

        if (! $certificate) {
            return response()->view('certificates.verify', [
                'valid' => false,
                'certificate' => null,
            ], 404);
        }

        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];

        return view('certificates.verify', [
            'valid' => true,
            'certificate' => [
                'student_name' => (string) ($meta['student_name'] ?? $certificate->user?->name ?? ''),
                'course_title' => (string) ($meta['course_name'] ?? $meta['course_title'] ?? $certificate->course?->getTranslation('title', app()->getLocale()) ?? ''),
                'issued_at' => $certificate->issued_at?->format('Y-m-d'),
                'completion_date' => (string) ($meta['completion_date'] ?? $certificate->issued_at?->format('d/m/Y') ?? ''),
                'certificate_number' => $certificate->certificate_number,
            ],
        ]);
    }
}
