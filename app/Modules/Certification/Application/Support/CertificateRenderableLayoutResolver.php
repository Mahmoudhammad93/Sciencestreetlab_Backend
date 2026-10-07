<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;

/**
 * Choose the structured admin layout over a raster + guessed overlay.
 */
final class CertificateRenderableLayoutResolver
{
    /**
     * @param  array<string, mixed>|null  $layout
     * @return array<string, mixed>|null
     */
    public function resolve(?array $layout, ?string $backgroundPath, ?CertificateTemplate $assigned = null): ?array
    {
        if ($this->hasStructuredElements($layout)) {
            return $layout;
        }

        if ($assigned !== null && $this->hasStructuredElements($assigned->layout_config)) {
            return is_array($assigned->layout_config) ? $assigned->layout_config : null;
        }

        $imported = $this->matchingImportedLayout($layout, $assigned);
        if ($imported !== null) {
            return $imported;
        }

        return $layout;
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

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array<string, mixed>|null
     */
    public function matchingImportedLayout(?array $layout, ?CertificateTemplate $assigned = null): ?array
    {
        $candidates = CertificateTemplate::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (CertificateTemplate $template): bool => $this->hasStructuredElements(
                is_array($template->layout_config) ? $template->layout_config : null
            ));

        if ($candidates->isEmpty()) {
            return null;
        }

        $targetColor = $this->normalizedColor(
            (string) (data_get($layout, 'page.background_color')
                ?: data_get($assigned?->layout_config, 'page.background_color')
                ?: '')
        );

        $yellow = $candidates->first(function (CertificateTemplate $template) {
            return $this->normalizedColor((string) data_get($template->layout_config, 'page.background_color')) === '#FDD700';
        });

        // Image-only admin JPEGs of this brand are the yellow imported family.
        if ($targetColor === '' || $targetColor === '#FFFFFF' || $targetColor === '#FDD700') {
            if ($yellow !== null && is_array($yellow->layout_config)) {
                return $yellow->layout_config;
            }
        }

        $colorMatch = $candidates->first(function (CertificateTemplate $template) use ($targetColor) {
            return $targetColor !== '' && $this->normalizedColor((string) data_get($template->layout_config, 'page.background_color')) === $targetColor;
        });

        if ($colorMatch !== null && is_array($colorMatch->layout_config)) {
            return $colorMatch->layout_config;
        }

        $first = $candidates->first();

        return is_array($first?->layout_config) ? $first->layout_config : null;
    }

    public function shouldDropRasterBackground(?array $resolvedLayout, ?string $backgroundPath): bool
    {
        if (! is_string($backgroundPath) || trim($backgroundPath) === '') {
            return false;
        }

        return $this->hasStructuredElements($resolvedLayout);
    }

    private function normalizedColor(string $color): string
    {
        $color = strtoupper(trim($color));
        if (preg_match('/^#([0-9A-F]{3})$/', $color, $m) === 1) {
            return sprintf('#%1$s%1$s%2$s%2$s%3$s%3$s', $m[1][0], $m[1][1], $m[1][2]);
        }

        return $color;
    }
}
