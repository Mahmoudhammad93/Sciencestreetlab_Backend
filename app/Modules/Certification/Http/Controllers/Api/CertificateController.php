<?php

declare(strict_types=1);

namespace App\Modules\Certification\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CertificateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $certificates = Certificate::query()
            ->where('user_id', $request->user()->id)
            ->with(['course:id,slug,title'])
            ->latest('issued_at')
            ->get()
            ->map(fn (Certificate $cert) => $this->transform($cert));

        return response()->json(['data' => $certificates]);
    }

    public function download(Request $request, string $uuid): StreamedResponse|JsonResponse
    {
        $certificate = Certificate::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $certificate->pdf_path || ! Storage::disk('local')->exists($certificate->pdf_path)) {
            return response()->json(['message' => 'PDF not ready yet.'], 404);
        }

        return Storage::disk('local')->download(
            $certificate->pdf_path,
            "certificate-{$certificate->certificate_number}.pdf"
        );
    }

    public function verify(string $code): JsonResponse
    {
        $certificate = Certificate::query()
            ->where('verification_code', $code)
            ->with(['user:id,name', 'course:id,title'])
            ->first();

        if (! $certificate) {
            return response()->json([
                'valid' => false,
                'message' => 'Certificate not found.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'student_name' => $certificate->user->name,
            'course_title' => $certificate->course->getTranslation('title', app()->getLocale()),
            'issued_at' => $certificate->issued_at->toIso8601String(),
            'certificate_number' => $certificate->certificate_number,
            'pdf_available' => $certificate->pdf_path && Storage::disk('local')->exists($certificate->pdf_path),
        ]);
    }

    /** @return array<string, mixed> */
    private function transform(Certificate $certificate): array
    {
        return [
            'uuid' => $certificate->uuid,
            'certificate_number' => $certificate->certificate_number,
            'course' => $certificate->course,
            'issued_at' => $certificate->issued_at->toIso8601String(),
            'verification_code' => $certificate->verification_code,
            'pdf_available' => $certificate->pdf_path && Storage::disk('local')->exists($certificate->pdf_path),
        ];
    }
}
