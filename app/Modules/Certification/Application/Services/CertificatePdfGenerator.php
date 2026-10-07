<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Services;

use App\Modules\Certification\Application\Support\CertificateDompdfFontRegistrar;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

final class CertificatePdfGenerator
{
    public function __construct(
        private readonly CertificateTemplateRenderer $renderer,
        private readonly CertificateDompdfFontRegistrar $fonts,
    ) {}

    public function generate(Certificate $certificate): string
    {
        $certificate->loadMissing(['user', 'course', 'template']);
        $this->renderer->hydrateIssuedSnapshot($certificate);
        $certificate->refresh()->loadMissing(['user', 'course', 'template']);

        $html = $this->renderer->renderForCertificate($certificate);
        $pdf = $this->makePdf($html, $this->pageFromCertificate($certificate));

        $path = "certificates/{$certificate->uuid}.pdf";
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    /**
     * Rebuild stale generic PDFs, then return the current stored path.
     */
    public function ensureGenerated(Certificate $certificate): string
    {
        $this->renderer->hydrateIssuedSnapshot($certificate);
        $certificate->refresh();
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];
        $path = is_string($certificate->pdf_path) ? $certificate->pdf_path : '';
        $fingerprint = $this->renderer->renderFingerprint($certificate);
        $pdfFresh = ($meta['pdf_renderer'] ?? null) === CertificateTemplateRenderer::RENDERER_VERSION
            && ($meta['render_fingerprint'] ?? null) === $fingerprint
            && $path !== ''
            && Storage::disk('local')->exists($path);

        if (! $pdfFresh) {
            $path = $this->generate($certificate);
            $certificate->refresh();
            $meta = is_array($certificate->metadata) ? $certificate->metadata : [];
            $certificate->update([
                'pdf_path' => $path,
                'metadata' => array_merge($meta, [
                    'pdf_renderer' => CertificateTemplateRenderer::RENDERER_VERSION,
                    'render_fingerprint' => $this->renderer->renderFingerprint($certificate->fresh()),
                ]),
            ]);
        }

        return $path;
    }

    /**
     * Preview PDF bytes for admin sample data (never persisted as Certificate).
     */
    public function previewPdf(CertificateTemplate $template): string
    {
        $html = $this->renderer->renderPreview($template);
        $layout = $this->renderer->normalizeLayout(
            is_array($template->layout_config) ? $template->layout_config : null
        );

        return $this->makePdf($html, $layout['page'])->output();
    }

    /**
     * @return array{orientation: string, width_mm: float, height_mm: float}
     */
    private function pageFromCertificate(Certificate $certificate): array
    {
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];
        $snapshot = is_array($meta['layout_snapshot'] ?? null) ? $meta['layout_snapshot'] : [];
        $page = is_array($snapshot['page'] ?? null) ? $snapshot['page'] : [];
        $live = is_array($certificate->template?->layout_config) ? $certificate->template->layout_config : [];
        $livePage = is_array($live['page'] ?? null) ? $live['page'] : [];

        $orientation = ($meta['orientation'] ?? $page['orientation'] ?? $livePage['orientation'] ?? 'landscape') === 'portrait'
            ? 'portrait'
            : 'landscape';

        $width = (float) ($meta['page_width_mm']
            ?? $page['width_mm']
            ?? $livePage['width_mm']
            ?? ($orientation === 'portrait' ? 210 : 297));
        $height = (float) ($meta['page_height_mm']
            ?? $page['height_mm']
            ?? $livePage['height_mm']
            ?? ($orientation === 'portrait' ? 297 : 210));

        return [
            'orientation' => $orientation,
            'width_mm' => $width,
            'height_mm' => $height,
        ];
    }

    /**
     * @param  array{width_mm?: float|int, height_mm?: float|int}  $page
     */
    private function makePdf(string $html, array $page): \Barryvdh\DomPDF\PDF
    {
        $widthMm = (float) ($page['width_mm'] ?? 297);
        $heightMm = (float) ($page['height_mm'] ?? 210);
        $widthPt = \App\Modules\Certification\Application\Support\CertificateCanvasGeometry::mmToPt($widthMm);
        $heightPt = \App\Modules\Certification\Application\Support\CertificateCanvasGeometry::mmToPt($heightMm);

        $pdf = Pdf::loadHTML($html);
        $dompdf = $pdf->getDomPDF();
        $options = $dompdf->getOptions();
        $options->setIsRemoteEnabled(true);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDpi(96);
        $this->fonts->register($dompdf);
        // Custom point box — do not also pass A4/orientation or DomPDF may pad a second page.
        $pdf->setPaper([0.0, 0.0, $widthPt, $heightPt]);

        return $pdf;
    }
}
