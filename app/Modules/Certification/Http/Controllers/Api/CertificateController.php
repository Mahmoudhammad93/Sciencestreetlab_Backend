<?php

declare(strict_types=1);

namespace App\Modules\Certification\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Certification\Application\Services\CertificatePdfGenerator;
use App\Modules\Certification\Application\Services\CertificateTemplateRenderer;
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

    public function show(Request $request, string $uuid): JsonResponse
    {
        $certificate = Certificate::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->with(['course:id,slug,title'])
            ->firstOrFail();

        return response()->json(['data' => $this->transform($certificate)]);
    }

    public function preview(Request $request, string $uuid, CertificateTemplateRenderer $renderer): JsonResponse
    {
        $certificate = Certificate::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->with(['user:id,name', 'course:id,slug,title', 'template'])
            ->firstOrFail();

        $payload = $renderer->canonicalView($certificate, forBrowser: true);
        $payload['uuid'] = $certificate->uuid;
        $payload['issued_at'] = $certificate->issued_at?->toIso8601String();

        return response()
            ->json(['data' => $payload])
            ->header('Content-Type', 'application/json');
    }

    public function download(Request $request, string $uuid, CertificatePdfGenerator $pdfs): StreamedResponse|JsonResponse
    {
        $certificate = Certificate::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->with(['user:id,name', 'course:id,slug,title', 'template'])
            ->firstOrFail();

        $path = $pdfs->ensureGenerated($certificate);
        $certificate->refresh();

        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'PDF not ready yet.'], 404);
        }

        return Storage::disk('local')->download(
            $path,
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

        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];

        return response()->json([
            'valid' => true,
            'student_name' => (string) ($meta['student_name'] ?? $certificate->user->name),
            'course_title' => (string) ($meta['course_name'] ?? $meta['course_title'] ?? $certificate->course->getTranslation('title', app()->getLocale())),
            'issued_at' => $certificate->issued_at->toIso8601String(),
            'completion_date' => (string) ($meta['completion_date'] ?? $certificate->issued_at->format('d/m/Y')),
            'certificate_number' => $certificate->certificate_number,
            'verification_url' => url('/certificates/verify/'.$certificate->verification_code),
            'pdf_available' => $certificate->pdf_path && Storage::disk('local')->exists($certificate->pdf_path),
        ]);
    }

    /** @return array<string, mixed> */
    private function transform(Certificate $certificate): array
    {
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];

        return [
            'uuid' => $certificate->uuid,
            'certificate_number' => $certificate->certificate_number,
            'course' => $certificate->course,
            'issued_at' => $certificate->issued_at->toIso8601String(),
            'verification_code' => $certificate->verification_code,
            'verification_url' => url('/certificates/verify/'.$certificate->verification_code),
            'student_name' => (string) ($meta['student_name'] ?? $certificate->user?->name ?? ''),
            'template_id' => $certificate->template_id,
            'preview_available' => true,
            'pdf_available' => true,
        ];
    }
}
