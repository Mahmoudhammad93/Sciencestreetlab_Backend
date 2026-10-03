<?php

declare(strict_types=1);

namespace App\Modules\Content\Application\Services;

use App\Support\PublicMediaUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates responsive WebP variants beside the original slide image.
 * Originals are never overwritten. No DDL — files live on the public disk.
 */
final class HomeSlideImageVariantService
{
    /** @var list<int> */
    public const WIDTHS = [480, 768, 1200, 1600];

    public const SIZES = '(max-width: 640px) 75vw, (max-width: 1024px) 50vw, 560px';

    /**
     * @return array{
     *   image_url: string|null,
     *   image_srcset: string|null,
     *   image_sizes: string|null,
     *   image_width: int|null,
     *   image_height: int|null
     * }
     */
    public function describe(?string $storedPath): array
    {
        $originalUrl = PublicMediaUrl::make($storedPath);

        $empty = [
            'image_url' => $originalUrl,
            'image_srcset' => null,
            'image_sizes' => null,
            'image_width' => null,
            'image_height' => null,
        ];

        if ($storedPath === null || $storedPath === '') {
            return $empty;
        }

        $diskPath = PublicMediaUrl::toDiskPath($storedPath) ?? ltrim($storedPath, '/');

        if (
            str_starts_with($diskPath, 'http://')
            || str_starts_with($diskPath, 'https://')
            || ! str_starts_with($diskPath, 'home-slides/')
        ) {
            return $empty;
        }

        if (! Storage::disk('public')->exists($diskPath)) {
            return $empty;
        }

        try {
            $variants = $this->ensureVariants($diskPath);
        } catch (Throwable $e) {
            Log::warning('home_slide_variant_failed', [
                'path' => $diskPath,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }

        if ($variants['srcset'] === '') {
            return $empty;
        }

        // Prefer a mid-size as the src fallback (mobile-first progressive enhancement).
        $fallback = $variants['urls'][768] ?? $variants['urls'][480] ?? $originalUrl;

        return [
            'image_url' => $fallback,
            'image_srcset' => $variants['srcset'],
            'image_sizes' => self::SIZES,
            'image_width' => $variants['width'],
            'image_height' => $variants['height'],
        ];
    }

    /**
     * Delete generated variants for a stored original (basename match).
     */
    public function deleteVariantsFor(?string $storedPath): void
    {
        if ($storedPath === null || $storedPath === '') {
            return;
        }

        $diskPath = PublicMediaUrl::toDiskPath($storedPath) ?? ltrim($storedPath, '/');
        if (! str_starts_with($diskPath, 'home-slides/')) {
            return;
        }

        $base = pathinfo($diskPath, PATHINFO_FILENAME);
        foreach (self::WIDTHS as $width) {
            Storage::disk('public')->delete($this->variantRelativePath($base, $width));
        }
    }

    /**
     * @return array{srcset: string, urls: array<int, string>, width: int|null, height: int|null}
     */
    private function ensureVariants(string $diskPath): array
    {
        $absolute = Storage::disk('public')->path($diskPath);
        $base = pathinfo($diskPath, PATHINFO_FILENAME);

        $info = @getimagesize($absolute);
        $origW = is_array($info) ? (int) $info[0] : null;
        $origH = is_array($info) ? (int) $info[1] : null;

        $urls = [];
        $parts = [];

        foreach (self::WIDTHS as $width) {
            if ($origW !== null && $origW > 0 && $width > $origW) {
                // Skip upscaling — still expose original at its native width once below.
                continue;
            }

            $relative = $this->variantRelativePath($base, $width);
            if (! Storage::disk('public')->exists($relative)) {
                $this->writeVariant($absolute, $relative, $width);
            }

            if (! Storage::disk('public')->exists($relative)) {
                continue;
            }

            $url = Storage::disk('public')->url($relative);
            $urls[$width] = $url;
            $parts[] = $url.' '.$width.'w';
        }

        // Always include the original as the largest candidate when larger than variants.
        $originalUrl = Storage::disk('public')->url($diskPath);
        if ($origW !== null && $origW > 0) {
            $already = false;
            foreach (array_keys($urls) as $w) {
                if (abs($w - $origW) < 8) {
                    $already = true;
                    break;
                }
            }
            if (! $already) {
                $parts[] = $originalUrl.' '.$origW.'w';
            }
        }

        return [
            'srcset' => implode(', ', $parts),
            'urls' => $urls,
            'width' => $origW,
            'height' => $origH,
        ];
    }

    private function variantRelativePath(string $base, int $width): string
    {
        $ext = function_exists('imagewebp') ? 'webp' : 'jpg';

        return "home-slides/variants/{$base}-w{$width}.{$ext}";
    }

    private function writeVariant(string $absoluteSource, string $relativeDest, int $targetWidth): void
    {
        if (! function_exists('imagecreatefromstring')) {
            return;
        }

        $canWebp = function_exists('imagewebp');
        $canJpeg = function_exists('imagejpeg');
        if (! $canWebp && ! $canJpeg) {
            return;
        }

        $bytes = @file_get_contents($absoluteSource);
        if ($bytes === false || $bytes === '') {
            return;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($src);

            return;
        }

        $targetWidth = min($targetWidth, $srcW);
        $targetHeight = (int) max(1, round($srcH * ($targetWidth / $srcW)));

        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($dst === false) {
            imagedestroy($src);

            return;
        }

        if ($canWebp) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $targetWidth, $targetHeight, $transparent);
            }
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            if ($white !== false) {
                imagefilledrectangle($dst, 0, 0, $targetWidth, $targetHeight, $white);
            }
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH);

        Storage::disk('public')->makeDirectory('home-slides/variants');
        $destAbsolute = Storage::disk('public')->path($relativeDest);

        $qualities = $targetWidth <= 480 ? [80, 72, 64] : [82, 75];
        $written = false;
        foreach ($qualities as $quality) {
            $ok = $canWebp
                ? @imagewebp($dst, $destAbsolute, $quality)
                : @imagejpeg($dst, $destAbsolute, $quality);

            if ($ok) {
                $written = true;
                $size = @filesize($destAbsolute) ?: 0;
                if ($targetWidth > 480 || $size <= 200_000) {
                    break;
                }
            }
        }

        imagedestroy($dst);
        imagedestroy($src);

        if (! $written) {
            @unlink($destAbsolute);
        }
    }
}
