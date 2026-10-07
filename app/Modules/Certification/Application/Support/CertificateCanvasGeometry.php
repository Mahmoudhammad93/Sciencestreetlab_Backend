<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

/**
 * One logical certificate canvas. Source unit is millimetres.
 *
 * SOURCE_UNIT: mm
 * HTML_UNIT:   percent of the canvas (same numbers as PDF)
 * PDF_UNIT:    the page box is mm, converted to points only for DomPDF paper
 * CONVERSION:  pt = mm * 72 / 25.4
 * CSS_PX_96:   px = mm * 96 / 25.4
 */
final class CertificateCanvasGeometry
{
    public const MM_PER_INCH = 25.4;

    public const PDF_DPI = 72.0;

    public const CSS_DPI = 96.0;

    /**
     * @param  array<string, mixed>  $page
     * @return array{width_mm: float, height_mm: float, orientation: string}
     */
    public static function page(array $page): array
    {
        $orientation = ($page['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $width = (float) ($page['width_mm'] ?? ($orientation === 'portrait' ? 210 : 297));
        $height = (float) ($page['height_mm'] ?? ($orientation === 'portrait' ? 297 : 210));

        return [
            'width_mm' => $width,
            'height_mm' => $height,
            'orientation' => $orientation,
        ];
    }

    public static function aspectRatio(float $widthMm, float $heightMm): float
    {
        return $heightMm > 0 ? $widthMm / $heightMm : 1.0;
    }

    public static function mmToPt(float $mm): float
    {
        return round($mm * self::PDF_DPI / self::MM_PER_INCH, 3);
    }

    public static function mmToCssPx(float $mm): float
    {
        return $mm * self::CSS_DPI / self::MM_PER_INCH;
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function clampBox(float $x, float $y, float $width, float $height, float $pageW, float $pageH): array
    {
        $x = max(0.0, min($x, max(0.0, $pageW)));
        $y = max(0.0, min($y, max(0.0, $pageH)));
        $width = max(0.5, min($width, max(0.5, $pageW - $x)));
        $height = max(0.5, min($height, max(0.5, $pageH - $y)));

        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @return array{left: float, top: float, width: float, height: float}
     */
    public static function boxToPercent(float $x, float $y, float $width, float $height, float $pageW, float $pageH): array
    {
        $safeW = $pageW > 0 ? $pageW : 1;
        $safeH = $pageH > 0 ? $pageH : 1;

        return [
            'left' => ($x / $safeW) * 100,
            'top' => ($y / $safeH) * 100,
            'width' => ($width / $safeW) * 100,
            'height' => ($height / $safeH) * 100,
        ];
    }

    public static function containScale(float $availableWidth, float $availableHeight, float $canvasWidth, float $canvasHeight): float
    {
        if ($canvasWidth <= 0 || $canvasHeight <= 0) {
            return 1.0;
        }

        $scale = min($availableWidth / $canvasWidth, $availableHeight / $canvasHeight);

        return (is_finite($scale) && $scale > 0) ? $scale : 1.0;
    }
}
