<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

/**
 * Safe local font mapping for certificate import / PDF / builder.
 * Never fetches remote fonts.
 */
final class CertificateFontRegistry
{
    public const FAMILY_CAIRO = 'Cairo';

    /**
     * Requested family (lowercased) => DomPDF / CSS family.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'dejavu sans' => 'DejaVu Sans',
            'dejavusans' => 'DejaVu Sans',
            'dejavu sans mono' => 'DejaVu Sans',
            'courier' => 'Courier',
            'courier new' => 'Courier',
            'helvetica' => 'Helvetica',
            'arial' => 'Helvetica',
            'sans-serif' => self::FAMILY_CAIRO,
            'serif' => 'Times',
            'times' => 'Times',
            'times new roman' => 'Times',
            'cairo' => self::FAMILY_CAIRO,
            'tajawal' => self::FAMILY_CAIRO,
            'noto sans arabic' => 'DejaVu Sans',
            'noto naskh arabic' => 'DejaVu Sans',
            'amiri' => 'DejaVu Sans',
            'system-ui' => self::FAMILY_CAIRO,
            'ui-sans-serif' => self::FAMILY_CAIRO,
            // Per-weight DomPDF faces (also valid CSS families)
            'cairo medium' => 'Cairo Medium',
            'cairo semibold' => 'Cairo SemiBold',
            'cairo bold' => 'Cairo Bold',
            'cairo extrabold' => 'Cairo ExtraBold',
        ];
    }

    /**
     * Local TTF paths keyed by CSS family name used in PDF face registration.
     *
     * @return array<string, string>
     */
    public static function localFontFiles(): array
    {
        $base = resource_path('fonts/certificates');

        return [
            self::FAMILY_CAIRO => $base.'/Cairo-Regular.ttf',
            'Cairo Medium' => $base.'/Cairo-Medium.ttf',
            'Cairo SemiBold' => $base.'/Cairo-SemiBold.ttf',
            'Cairo Bold' => $base.'/Cairo-Bold.ttf',
            'Cairo ExtraBold' => $base.'/Cairo-ExtraBold.ttf',
        ];
    }

    public static function defaultFamily(): string
    {
        return self::FAMILY_CAIRO;
    }

    /**
     * Normalize CSS font-weight to a registry token.
     */
    public static function normalizeWeight(string|int|null $value): string
    {
        $value = strtolower(trim((string) ($value ?? '400')));

        return match (true) {
            in_array($value, ['100', '200', '300', 'lighter'], true) => '400',
            in_array($value, ['normal', '400', 'regular'], true) => '400',
            in_array($value, ['500', 'medium'], true) => '500',
            in_array($value, ['600', 'semibold', 'demi', 'demibold'], true) => '600',
            in_array($value, ['700', 'bold', 'bolder'], true) => '700',
            in_array($value, ['800', 'extrabold', 'ultrabold'], true) => '800',
            in_array($value, ['900', 'black', 'heavy'], true) => '800',
            is_numeric($value) && (int) $value >= 800 => '800',
            is_numeric($value) && (int) $value >= 700 => '700',
            is_numeric($value) && (int) $value >= 600 => '600',
            is_numeric($value) && (int) $value >= 500 => '500',
            default => '400',
        };
    }

    /**
     * DomPDF only reliably switches normal/bold per family, so map weight → dedicated face.
     */
    public static function pdfFamily(string $family, string|int|null $weight = '400'): string
    {
        $resolved = self::resolve($family);
        $face = $resolved['family'];
        $weight = self::normalizeWeight($weight);
        $local = self::localFontFiles();

        if (strcasecmp($face, self::FAMILY_CAIRO) === 0) {
            $face = match ($weight) {
                '500' => 'Cairo Medium',
                '600' => 'Cairo SemiBold',
                '700' => 'Cairo Bold',
                '800' => 'Cairo ExtraBold',
                default => self::FAMILY_CAIRO,
            };
        }

        if (isset($local[$face]) && ! is_file($local[$face])) {
            return 'DejaVu Sans';
        }

        if (str_starts_with(strtolower($face), 'cairo') && ! isset($local[$face])) {
            return 'DejaVu Sans';
        }

        return $face;
    }

    /**
     * Browser stack: prefer Cairo, then system Arabic-capable faces.
     */
    public static function browserFamily(string $family): string
    {
        $resolved = self::resolve($family);
        $face = $resolved['family'];

        if (str_starts_with(strtolower($face), 'cairo') || $resolved['substituted']) {
            return "Cairo, Tahoma, 'Noto Naskh Arabic', 'DejaVu Sans', sans-serif";
        }

        return $face;
    }

    public static function arabicFallbackFamily(): string
    {
        return 'DejaVu Sans';
    }

    public static function quoteCssFamily(string $family): string
    {
        $family = trim($family);
        if ($family === '' || str_contains($family, ',')) {
            return $family !== '' ? $family : "'DejaVu Sans', sans-serif";
        }

        $clean = trim($family, " \t\n\r'\"");

        return "'".$clean."', 'DejaVu Sans', sans-serif";
    }

    /**
     * @return array{family: string, substituted: bool, requested: string, warning: ?string}
     */
    public static function resolve(string $fontFamilyDeclaration): array
    {
        $parts = array_map(
            static fn (string $p): string => trim($p, " \t\n\r\0\x0B'\""),
            explode(',', $fontFamilyDeclaration)
        );

        $aliases = self::aliases();
        $local = self::localFontFiles();
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $key = strtolower($part);
            if (! isset($aliases[$key])) {
                continue;
            }
            $mapped = $aliases[$key];
            $available = isset($local[$mapped]) && is_file($local[$mapped]);
            if (! $available && str_starts_with(strtolower($mapped), 'cairo')) {
                // Fall back only if files missing.
                return [
                    'family' => 'DejaVu Sans',
                    'substituted' => true,
                    'requested' => $part,
                    'warning' => "Font '{$part}' files missing locally; using 'DejaVu Sans'.",
                ];
            }

            $native = in_array($key, ['cairo', 'dejavu sans', 'dejavusans', 'helvetica', 'courier', 'times', 'cairo medium', 'cairo semibold', 'cairo bold', 'cairo extrabold'], true);

            return [
                'family' => $mapped,
                'substituted' => ! $native && $mapped !== $part,
                'requested' => $part,
                'warning' => (! $native && $mapped !== $part)
                    ? "Font '{$part}' mapped to local '{$mapped}'."
                    : null,
            ];
        }

        $requested = $parts[0] !== '' ? $parts[0] : self::defaultFamily();

        return [
            'family' => self::defaultFamily(),
            'substituted' => true,
            'requested' => $requested,
            'warning' => "Font '{$requested}' is unavailable; using '".self::defaultFamily()."'.",
        ];
    }

    /**
     * Public URLs for builder @font-face (local only).
     *
     * @return list<array{family: string, weight: string, url: string}>
     */
    public static function browserFaces(): array
    {
        $faces = [
            ['file' => 'Cairo-Regular.ttf', 'weight' => '400'],
            ['file' => 'Cairo-Medium.ttf', 'weight' => '500'],
            ['file' => 'Cairo-SemiBold.ttf', 'weight' => '600'],
            ['file' => 'Cairo-Bold.ttf', 'weight' => '700'],
            ['file' => 'Cairo-ExtraBold.ttf', 'weight' => '800'],
        ];

        $out = [];
        foreach ($faces as $face) {
            $path = public_path('fonts/certificates/'.$face['file']);
            if (! is_file($path)) {
                continue;
            }
            $out[] = [
                'family' => self::FAMILY_CAIRO,
                'weight' => $face['weight'],
                'url' => asset('fonts/certificates/'.$face['file']),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function selectableFamilies(): array
    {
        return array_values(array_unique([
            self::FAMILY_CAIRO,
            'DejaVu Sans',
            'Helvetica',
            'Times',
            'Courier',
        ]));
    }
}
