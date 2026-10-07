<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Services;

use App\Modules\Certification\Application\Support\CertificateArabicPdfText;
use App\Modules\Certification\Application\Support\CertificateCanvasGeometry;
use App\Modules\Certification\Application\Support\CertificateDynamicFieldBinder;
use App\Modules\Certification\Application\Support\CertificateFontRegistry;
use App\Modules\Certification\Application\Support\CertificateLayoutPresets;
use App\Modules\Certification\Application\Support\CertificateRenderableLayoutResolver;
use App\Modules\Certification\Application\Support\CertificateVariableRegistry;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Support\PublicMediaUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

final class CertificateTemplateRenderer
{
    public const RENDERER_VERSION = 'template-v2';

    public const STYLE_REVISION = 'pdf-nbsp-fit';

    public function __construct(
        private readonly CertificateQrCodeRenderer $qrCodes,
        private readonly CertificateRenderableLayoutResolver $layouts,
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
    public function renderHtml(
        ?array $layout,
        array $variables,
        ?string $backgroundPath = null,
        ?string $signaturePath = null,
        bool $forBrowser = false,
        bool $embed = false,
        ?CertificateTemplate $assigned = null,
    ): string {
        $layout = $this->resolveLayout($layout, $backgroundPath, $assigned);
        $layout = CertificateDynamicFieldBinder::bind($layout, $variables);
        $page = $layout['page'];
        $defaults = is_array($layout['defaults'] ?? null) ? $layout['defaults'] : [];
        $merged = array_merge($defaults, array_filter($variables, fn ($v) => $v !== null && $v !== ''));

        $elements = [];
        foreach ($layout['elements'] as $element) {
            if (! is_array($element)) {
                continue;
            }
            if (array_key_exists('visible', $element) && ! $element['visible']) {
                continue;
            }
            $elements[] = $this->prepareElement($element, $merged, $signaturePath, $forBrowser, $page);
        }

        usort($elements, function (array $a, array $b): int {
            $layer = static fn (array $el): int => match ($el['type'] ?? 'text') {
                'svg', 'image', 'decoration' => 0,
                'line', 'qr', 'signature_block' => 1,
                default => 2,
            };

            return [$layer($a), (int) ($a['z_index'] ?? 0)] <=> [$layer($b), (int) ($b['z_index'] ?? 0)];
        });

        $dropRaster = $this->layouts->shouldDropRasterBackground($layout, $backgroundPath);
        $bgUrl = $dropRaster ? null : $this->publicAssetUrl($backgroundPath, $forBrowser);

        $html = View::make('certificates.dynamic', [
            'page' => $page,
            'elements' => $elements,
            'backgroundUrl' => $bgUrl,
            'embed' => $embed,
            'forBrowser' => $forBrowser,
        ])->render();

        return $this->sanitizeHtml($html);
    }

    public function renderForCertificate(Certificate $certificate, bool $forBrowser = false, bool $embed = false): string
    {
        $certificate->loadMissing(['user', 'course', 'template']);
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];

        $layout = is_array($meta['layout_snapshot'] ?? null)
            ? $meta['layout_snapshot']
            : ($certificate->template?->layout_config);

        $variables = $this->variablesFromCertificate($certificate);
        $background = $this->layouts->shouldDropRasterBackground(
            is_array($layout) ? $layout : null,
            is_string($meta['background_path'] ?? null)
                ? $meta['background_path']
                : $certificate->template?->background_path
        ) ? null : (is_string($meta['background_path'] ?? null)
            ? $meta['background_path']
            : $certificate->template?->background_path);
        $signature = is_string($meta['signature_path'] ?? null)
            ? $meta['signature_path']
            : data_get($layout, 'page.signature_path');

        return $this->renderHtml(
            is_array($layout) ? $layout : null,
            $variables,
            is_string($background) ? $background : null,
            is_string($signature) ? $signature : null,
            $forBrowser,
            $embed,
            $certificate->template,
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
            forBrowser: false,
            embed: false,
            assigned: $template,
        );
    }

    /**
     * Persist a renderer snapshot for certificates issued before template-v1.
     * Does not change user_id, course_id, template_id, or certificate_number.
     */
    public function hydrateIssuedSnapshot(Certificate $certificate): bool
    {
        $certificate->loadMissing(['user', 'course', 'template']);
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];

        if (! $this->snapshotNeedsRebuild($certificate)) {
            return false;
        }

        $template = $certificate->template;
        if ($template === null) {
            $imported = $this->layouts->matchingImportedLayout(
                is_array($meta['layout_snapshot'] ?? null) ? $meta['layout_snapshot'] : null
            );
            if ($imported === null) {
                return false;
            }
            $template = new CertificateTemplate([
                'slug' => (string) ($meta['template_slug'] ?? 'imported-resolved'),
                'layout_config' => $imported,
                'background_path' => null,
            ]);
        }

        $snapshot = $this->buildIssuanceSnapshot($template, $this->variablesFromCertificate($certificate));

        $certificate->update([
            'metadata' => array_merge($meta, $snapshot, [
                'renderer' => self::RENDERER_VERSION,
                'hydrated_at' => now()->toIso8601String(),
            ]),
        ]);

        return true;
    }

    /**
     * Canonical payload shared by HTML preview and PDF generation.
     *
     * @return array<string, mixed>
     */
    public function canonicalView(Certificate $certificate, bool $forBrowser = false): array
    {
        $this->hydrateIssuedSnapshot($certificate);
        $certificate->refresh()->loadMissing(['user', 'course', 'template']);

        $variables = $this->variablesFromCertificate($certificate);
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];
        $page = is_array(data_get($meta, 'layout_snapshot.page'))
            ? $meta['layout_snapshot']['page']
            : [
                'width_mm' => $meta['page_width_mm'] ?? 297,
                'height_mm' => $meta['page_height_mm'] ?? 210,
                'orientation' => $meta['orientation'] ?? 'landscape',
            ];

        return [
            'html' => $this->renderForCertificate($certificate, $forBrowser, $forBrowser),
            'student_name' => $variables['student_name'],
            'course_title' => $variables['course_name'],
            'completion_date' => $variables['completion_date'],
            'issue_date' => $variables['issue_date'],
            'certificate_number' => $variables['certificate_number'],
            'template_id' => $certificate->template_id,
            'template_slug' => (string) ($meta['template_slug'] ?? $certificate->template?->slug ?? ''),
            'template_version' => self::RENDERER_VERSION,
            'renderer' => self::RENDERER_VERSION,
            'render_fingerprint' => $this->renderFingerprint($certificate),
            'page' => [
                'width_mm' => (float) ($page['width_mm'] ?? 297),
                'height_mm' => (float) ($page['height_mm'] ?? 210),
                'orientation' => (string) ($page['orientation'] ?? 'landscape'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildIssuanceSnapshot(CertificateTemplate $template, array $variables): array
    {
        $layout = $this->resolveLayout(
            is_array($template->layout_config) ? $template->layout_config : null,
            $template->background_path,
            $template,
        );
        $layout = CertificateDynamicFieldBinder::bind($layout, $variables);
        $defaults = is_array($layout['defaults'] ?? null) ? $layout['defaults'] : [];
        $merged = array_merge($defaults, $variables);
        $backgroundPath = $this->layouts->shouldDropRasterBackground($layout, $template->background_path)
            ? null
            : $template->background_path;

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
            'background_path' => $backgroundPath,
            'signature_path' => data_get($layout, 'page.signature_path'),
            'layout_snapshot' => $layout,
            'orientation' => $layout['page']['orientation'] ?? 'landscape',
            'page_width_mm' => $layout['page']['width_mm'] ?? 297,
            'page_height_mm' => $layout['page']['height_mm'] ?? 210,
            'renderer' => self::RENDERER_VERSION,
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
            'student_name' => (string) ($certificate->user?->name ?? $meta['student_name'] ?? ''),
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
     * @param  array<string, mixed>|null  $layout
     * @return array{page: array<string, mixed>, elements: list<array<string, mixed>>, defaults: array<string, string>}
     */
    public function resolveLayout(?array $layout, ?string $backgroundPath = null, ?CertificateTemplate $assigned = null): array
    {
        $resolved = $this->layouts->resolve($layout, $backgroundPath, $assigned);
        $hasArtwork = is_string($backgroundPath) && trim($backgroundPath) !== '';

        if ($this->layouts->hasStructuredElements($resolved)) {
            return $this->normalizeLayout($resolved);
        }

        if ($hasArtwork && ! $this->layouts->hasStructuredElements($resolved)) {
            return $this->normalizeLayout(CertificateLayoutPresets::artworkLandscapeOverlay());
        }

        return $this->normalizeLayout($resolved);
    }

    public function snapshotNeedsRebuild(Certificate $certificate): bool
    {
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];
        if (($meta['renderer'] ?? null) !== self::RENDERER_VERSION) {
            return true;
        }

        $snapshot = is_array($meta['layout_snapshot'] ?? null) ? $meta['layout_snapshot'] : null;
        if ($snapshot === null) {
            return true;
        }

        if ((string) data_get($snapshot, 'page.preset') === CertificateLayoutPresets::ARTWORK_LANDSCAPE_OVERLAY) {
            return true;
        }

        return false;
    }

    public function renderFingerprint(Certificate $certificate): string
    {
        $variables = $this->variablesFromCertificate($certificate);
        $meta = is_array($certificate->metadata) ? $certificate->metadata : [];

        return hash('sha256', json_encode([
            'renderer' => self::RENDERER_VERSION,
            'style_revision' => self::STYLE_REVISION,
            'student_name' => $variables['student_name'],
            'course_name' => $variables['course_name'],
            'certificate_number' => $variables['certificate_number'],
            'completion_date' => $variables['completion_date'],
            'layout' => $meta['layout_snapshot'] ?? null,
            'template_id' => $certificate->template_id,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, string|null>  $variables
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $page
     */
    private function prepareElement(array $element, array $variables, ?string $signaturePath, bool $forBrowser, array $page = []): array
    {
        $type = (string) ($element['type'] ?? 'text');
        $prepared = $element;
        $prepared['type'] = $type;

        $pageBox = CertificateCanvasGeometry::page($page);
        $box = CertificateCanvasGeometry::clampBox(
            (float) ($element['x'] ?? 0),
            (float) ($element['y'] ?? 0),
            (float) ($element['width'] ?? 40),
            (float) ($element['height'] ?? 10),
            $pageBox['width_mm'],
            $pageBox['height_mm'],
        );
        $prepared['x'] = $box['x'];
        $prepared['y'] = $box['y'];
        $prepared['width'] = $box['width'];
        $prepared['height'] = $box['height'];
        $prepared['box_percent'] = CertificateCanvasGeometry::boxToPercent(
            $box['x'],
            $box['y'],
            $box['width'],
            $box['height'],
            $pageBox['width_mm'],
            $pageBox['height_mm'],
        );

        if ($type === 'text') {
            $content = (string) ($element['content'] ?? '');
            $resolved = CertificateVariableRegistry::resolve($content, $variables);
            $prepared['dir'] = CertificateArabicPdfText::resolveDirection($element, $resolved);
            $size = (float) ($element['font_size'] ?? 12);
            $boxHeightPt = $box['height'] * CertificateCanvasGeometry::PDF_DPI / CertificateCanvasGeometry::MM_PER_INCH;
            if ($boxHeightPt > 0 && $size > $boxHeightPt * 0.92) {
                $size = round($boxHeightPt * 0.92, 2);
            }
            $prepared['font_size'] = $size;
            if (! $forBrowser) {
                $resolved = CertificateArabicPdfText::shapeForPdf($resolved);
                $prepared['letter_spacing'] = 'normal';
            }
            $prepared['resolved'] = e($resolved);
            $prepared['font_family_css'] = $forBrowser
                ? CertificateFontRegistry::browserFamily((string) ($element['font_family'] ?? CertificateFontRegistry::defaultFamily()))
                : CertificateFontRegistry::pdfFamily(
                    (string) ($element['font_family'] ?? CertificateFontRegistry::defaultFamily()),
                    $element['font_weight'] ?? '400'
                );
        }

        if ($type === 'signature_block') {
            $prepared['signer_name'] = e((string) ($variables['signer_name'] ?? ''));
            $prepared['signer_title'] = e((string) ($variables['signer_title'] ?? ''));
            $prepared['signature_url'] = $this->publicAssetUrl(
                is_string($element['signature_path'] ?? null)
                    ? $element['signature_path']
                    : $signaturePath,
                $forBrowser
            );
        }

        if ($type === 'image' || $type === 'svg') {
            $prepared['image_url'] = $this->publicAssetUrl(
                is_string($element['asset'] ?? null) ? $element['asset'] : null,
                $forBrowser
            );
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

    private function publicAssetUrl(?string $path, bool $forBrowser = false): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        $normalized = PublicMediaUrl::toDiskPath($path) ?? ltrim($path, '/');
        if (! $forBrowser && $normalized !== '' && Storage::disk('public')->exists($normalized)) {
            return Storage::disk('public')->path($normalized);
        }

        return PublicMediaUrl::make($path);
    }

    private function sanitizeHtml(string $html): string
    {
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = (string) preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string) preg_replace('/javascript:/i', '', $html);

        return $html;
    }
}
