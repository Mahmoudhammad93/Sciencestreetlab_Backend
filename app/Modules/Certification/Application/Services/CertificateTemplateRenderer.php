<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Services;

use App\Modules\Certification\Application\Support\CertificateLayoutPresets;
use App\Modules\Certification\Application\Support\CertificateVariableRegistry;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Support\PublicMediaUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

final class CertificateTemplateRenderer
{
    public function __construct(
        private readonly CertificateQrCodeRenderer $qrCodes,
    ) {}

    /**
     * Preview-only sample values — never persist as real certificates.
     *
     * @return array<string, string>
     */
    public static function sampleVariables(): array
    {
        return [
            'student_name' => 'Ahmed Mohamed',
            'course_name' => 'Microscope Course',
            'certificate_title' => 'CERTIFICATE',
            'certificate_subtitle' => 'OF COMPLETION',
            'intro_text' => 'THIS IS TO CERTIFY THAT',
            'achievement_text' => 'HAS COMPLETED Microscope Course WITH EXCELLENCE!',
            'completion_date' => '04/10/2026',
            'issue_date' => '04/10/2026',
            'certificate_number' => 'SSL-PREVIEW-0001',
            'signer_name' => 'Abdullah Annan',
            'signer_title' => 'Science Street Founder & CEO',
            'verification_url' => url('/certificates/verify/PREVIEW'),
            'brand_name' => 'شارع العلوم',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @param  array<string, string|null>  $variables
     */
    public function renderHtml(?array $layout, array $variables, ?string $backgroundPath = null, ?string $signaturePath = null): string
    {
        $layout = $this->normalizeLayout($layout);
        $page = $layout['page'];
        $defaults = is_array($layout['defaults'] ?? null) ? $layout['defaults'] : [];
        $merged = array_merge($defaults, array_filter($variables, fn ($v) => $v !== null && $v !== ''));

        $elements = [];
        foreach ($layout['elements'] as $element) {
            if (! is_array($element)) {
                continue;
            }
            // Hidden elements stay in layout_config but are omitted from preview/PDF.
            if (array_key_exists('visible', $element) && ! $element['visible']) {
                continue;
            }
            $elements[] = $this->prepareElement($element, $merged, $signaturePath);
        }

        usort($elements, fn ($a, $b) => ((int) ($a['z_index'] ?? 0)) <=> ((int) ($b['z_index'] ?? 0)));

        $bgUrl = $this->publicAssetUrl($backgroundPath);

        return View::make('certificates.dynamic', [
            'page' => $page,
            'elements' => $elements,
            'backgroundUrl' => $bgUrl,
        ])->render();
    }

    public function renderForCertificate(Certificate $certificate): string
    {
        $certificate->loadMissing(['user', 'course', 'template']);
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];

        $layout = is_array($meta['layout_snapshot'] ?? null)
            ? $meta['layout_snapshot']
            : ($certificate->template?->layout_config);

        $variables = $this->variablesFromCertificate($certificate);
        $background = is_string($meta['background_path'] ?? null)
            ? $meta['background_path']
            : $certificate->template?->background_path;
        $signature = is_string($meta['signature_path'] ?? null)
            ? $meta['signature_path']
            : data_get($layout, 'page.signature_path');

        return $this->renderHtml(
            is_array($layout) ? $layout : null,
            $variables,
            is_string($background) ? $background : null,
            is_string($signature) ? $signature : null,
        );
    }

    public function renderPreview(CertificateTemplate $template): string
    {
        $layout = $template->layout_config;
        $signature = data_get($layout, 'page.signature_path');

        return $this->renderHtml(
            is_array($layout) ? $layout : null,
            self::sampleVariables(),
            $template->background_path,
            is_string($signature) ? $signature : null,
        );
    }

    /**
     * Build snapshot metadata for historical correctness.
     *
     * @return array<string, mixed>
     */
    public function buildIssuanceSnapshot(CertificateTemplate $template, array $variables): array
    {
        $layout = $this->normalizeLayout(is_array($template->layout_config) ? $template->layout_config : null);
        $defaults = is_array($layout['defaults'] ?? null) ? $layout['defaults'] : [];
        $merged = array_merge($defaults, $variables);

        return [
            'student_name' => $merged['student_name'] ?? null,
            'course_name' => $merged['course_name'] ?? null,
            'certificate_title' => $merged['certificate_title'] ?? null,
            'certificate_subtitle' => $merged['certificate_subtitle'] ?? null,
            'intro_text' => $merged['intro_text'] ?? null,
            'achievement_text' => CertificateVariableRegistry::resolve(
                (string) ($merged['achievement_text'] ?? ''),
                $merged
            ),
            'completion_date' => $merged['completion_date'] ?? null,
            'issue_date' => $merged['issue_date'] ?? null,
            'certificate_number' => $merged['certificate_number'] ?? null,
            'signer_name' => $merged['signer_name'] ?? null,
            'signer_title' => $merged['signer_title'] ?? null,
            'verification_url' => $merged['verification_url'] ?? null,
            'brand_name' => $merged['brand_name'] ?? null,
            'template_slug' => $template->slug,
            'template_id' => $template->id,
            'background_path' => $template->background_path,
            'signature_path' => data_get($layout, 'page.signature_path'),
            'layout_snapshot' => $layout,
            'orientation' => $layout['page']['orientation'] ?? 'landscape',
            'page_width_mm' => $layout['page']['width_mm'] ?? 297,
            'page_height_mm' => $layout['page']['height_mm'] ?? 210,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function variablesFromCertificate(Certificate $certificate): array
    {
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];
        $locale = app()->getLocale();

        return [
            'student_name' => (string) ($meta['student_name'] ?? $certificate->user?->name ?? ''),
            'course_name' => (string) ($meta['course_name'] ?? $meta['course_title'] ?? $certificate->course?->getTranslation('title', $locale) ?? ''),
            'certificate_title' => (string) ($meta['certificate_title'] ?? 'CERTIFICATE'),
            'certificate_subtitle' => (string) ($meta['certificate_subtitle'] ?? 'OF COMPLETION'),
            'intro_text' => (string) ($meta['intro_text'] ?? 'THIS IS TO CERTIFY THAT'),
            'achievement_text' => (string) ($meta['achievement_text'] ?? ''),
            'completion_date' => (string) ($meta['completion_date'] ?? $certificate->issued_at?->format('d/m/Y') ?? ''),
            'issue_date' => (string) ($meta['issue_date'] ?? $certificate->issued_at?->format('d/m/Y') ?? ''),
            'certificate_number' => (string) ($meta['certificate_number'] ?? $certificate->certificate_number ?? ''),
            'signer_name' => (string) ($meta['signer_name'] ?? ''),
            'signer_title' => (string) ($meta['signer_title'] ?? ''),
            'verification_url' => (string) ($meta['verification_url'] ?? url('/certificates/verify/'.$certificate->verification_code)),
            'brand_name' => (string) ($meta['brand_name'] ?? 'شارع العلوم'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array{page: array<string, mixed>, elements: list<array<string, mixed>>, defaults: array<string, string>}
     */
    public function normalizeLayout(?array $layout): array
    {
        if ($layout === null || $layout === []) {
            $layout = CertificateLayoutPresets::layout(CertificateLayoutPresets::CYAN_LANDSCAPE_COMPLETION);
        }

        $page = is_array($layout['page'] ?? null) ? $layout['page'] : [];
        $orientation = ($page['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $width = (float) ($page['width_mm'] ?? ($orientation === 'portrait' ? 210 : 297));
        $height = (float) ($page['height_mm'] ?? ($orientation === 'portrait' ? 297 : 210));

        return [
            'page' => [
                'orientation' => $orientation,
                'width_mm' => $width,
                'height_mm' => $height,
                'background_color' => (string) ($page['background_color'] ?? '#FFFFFF'),
                'border_color' => (string) ($page['border_color'] ?? '#2828a0'),
                'border_width_mm' => (float) ($page['border_width_mm'] ?? 6),
                'signature_path' => $page['signature_path'] ?? null,
                'preset' => $page['preset'] ?? null,
            ],
            'defaults' => is_array($layout['defaults'] ?? null)
                ? array_map(fn ($v) => (string) $v, $layout['defaults'])
                : [],
            'elements' => array_values(array_filter(
                is_array($layout['elements'] ?? null) ? $layout['elements'] : [],
                fn ($e) => is_array($e)
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, string|null>  $variables
     * @return array<string, mixed>
     */
    private function prepareElement(array $element, array $variables, ?string $signaturePath): array
    {
        $type = (string) ($element['type'] ?? 'text');
        $prepared = $element;
        $prepared['type'] = $type;

        if ($type === 'text') {
            $content = (string) ($element['content'] ?? '');
            $prepared['resolved'] = e(CertificateVariableRegistry::resolve($content, $variables));
        }

        if ($type === 'signature_block') {
            $prepared['signer_name'] = e((string) ($variables['signer_name'] ?? ''));
            $prepared['signer_title'] = e((string) ($variables['signer_title'] ?? ''));
            $prepared['signature_url'] = $this->publicAssetUrl(
                is_string($element['signature_path'] ?? null)
                    ? $element['signature_path']
                    : $signaturePath
            );
        }

        if ($type === 'image' || $type === 'svg') {
            $prepared['image_url'] = $this->publicAssetUrl(
                is_string($element['asset'] ?? null) ? $element['asset'] : null
            );
            // Prefer filesystem/data for DomPDF; keep sanitized markup for browser preview fallback.
            if ($type === 'svg' && is_string($element['svg_markup'] ?? null) && $element['svg_markup'] !== '') {
                $prepared['svg_markup'] = $element['svg_markup'];
            }
        }

        if ($type === 'qr') {
            $url = (string) ($variables['verification_url'] ?? '');
            $prepared['qr_data_uri'] = $url !== '' ? $this->qrCodes->dataUri($url) : null;
        }

        if ($type === 'decoration') {
            $prepared['decoration'] = (string) ($element['decoration'] ?? 'signpost');
            $prepared['label'] = e((string) ($variables['brand_name'] ?? 'شارع العلوم'));
        }

        return $prepared;
    }

    private function publicAssetUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        $normalized = PublicMediaUrl::toDiskPath($path) ?? ltrim($path, '/');
        if ($normalized !== '' && Storage::disk('public')->exists($normalized)) {
            // DomPDF prefers absolute filesystem paths for local assets.
            return Storage::disk('public')->path($normalized);
        }

        return PublicMediaUrl::make($path);
    }
}
