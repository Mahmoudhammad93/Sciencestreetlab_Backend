<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use Illuminate\Support\Facades\Storage;

/**
 * Prefer the uploaded dashboard artwork. Overlay only name + course.
 * Structured imported layouts are used only when there is no image.
 */
final class CertificateRenderableLayoutResolver
{
    /**
     * @param  array<string, mixed>|null  $layout
     * @return array<string, mixed>|null
     */
    public function resolve(?array $layout, ?string $backgroundPath, ?CertificateTemplate $assigned = null): ?array
    {
        $artwork = $this->artworkPath($backgroundPath, $assigned);
        if ($artwork !== null) {
            $page = CertificateCanvasGeometry::pageFromRaster($this->absolutePublicPath($artwork));

            return CertificateLayoutPresets::artworkLandscapeOverlay(
                $page['width_mm'],
                $page['height_mm'],
            );
        }

        if ($this->hasStructuredElements($layout)) {
            return $layout;
        }

        if ($assigned !== null && $this->hasStructuredElements($assigned->layout_config)) {
            return is_array($assigned->layout_config) ? $assigned->layout_config : null;
        }

        return $layout;
    }

    public function artworkPath(?string $backgroundPath, ?CertificateTemplate $assigned = null): ?string
    {
        foreach ([$backgroundPath, $assigned?->background_path] as $path) {
            if (is_string($path) && trim($path) !== '') {
                return $path;
            }
        }

        return null;
    }

    public function absolutePublicPath(string $path): ?string
    {
        if (str_starts_with($path, '/') && is_file($path)) {
            return $path;
        }

        $normalized = ltrim($path, '/');
        $absolute = Storage::disk('public')->path($normalized);

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * @param  array<string, mixed>|null  $layout
     */
    public function hasStructuredElements(?array $layout): bool
    {
        if ($layout === null) {
            return false;
        }

        $preset = (string) data_get($layout, 'page.preset', '');
        if ($preset === CertificateLayoutPresets::ARTWORK_LANDSCAPE_OVERLAY) {
            return false;
        }

        $elements = is_array($layout['elements'] ?? null) ? $layout['elements'] : [];
        $count = 0;
        foreach ($elements as $element) {
            if (is_array($element)) {
                $count++;
            }
        }

        return $count >= 3;
    }

    public function shouldDropRasterBackground(?array $resolvedLayout, ?string $backgroundPath): bool
    {
        return false;
    }
}
