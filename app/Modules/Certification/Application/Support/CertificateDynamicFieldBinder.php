<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

/**
 * Bind issued values into existing template boxes.
 * Never creates a new overlay element or moves a box.
 */
final class CertificateDynamicFieldBinder
{
    /** @var list<string> */
    private const HEADING_PHRASES = [
        'CERTIFICATE',
        'OF COMPLETION',
        'OF ACHIEVEMENT',
        'THIS IS TO CERTIFY THAT',
        'SCIENCE STREET FOUNDER & CEO',
        'SCIENCE STREET FOUNDER AND CEO',
    ];

    /**
     * @param  array{page?: array<string, mixed>, elements?: list<array<string, mixed>>, defaults?: array<string, mixed>}  $layout
     * @param  array<string, string|null>  $variables
     * @return array{page: array<string, mixed>, elements: list<array<string, mixed>>, defaults: array<string, mixed>}
     */
    public static function bind(array $layout, array $variables): array
    {
        $elements = is_array($layout['elements'] ?? null) ? $layout['elements'] : [];
        $mapped = [];

        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                continue;
            }

            $field = self::declaredField($element);
            if ($field !== null) {
                $mapped[$field] = true;
                if (CertificateVariableRegistry::isKnown($field) && ($element['type'] ?? 'text') === 'text') {
                    $elements[$index]['content'] = '{{ '.$field.' }}';
                    $elements[$index]['cert_field'] = $field;
                }
            }
        }

        if (! isset($mapped['student_name'])) {
            $nameIndex = self::inferStudentNameIndex($elements);
            if ($nameIndex !== null) {
                $elements[$nameIndex]['content'] = '{{ student_name }}';
                $elements[$nameIndex]['cert_field'] = 'student_name';
            }
        }

        $layout['elements'] = array_values(array_filter($elements, fn ($e) => is_array($e)));

        return $layout;
    }

    /**
     * @param  array<string, mixed>  $element
     */
    public static function declaredField(array $element): ?string
    {
        $field = strtolower(trim((string) ($element['cert_field'] ?? '')));
        if ($field !== '' && CertificateVariableRegistry::isKnown($field)) {
            return $field;
        }

        $content = (string) ($element['content'] ?? '');
        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u', $content, $matches) === 1) {
            $key = $matches[1][0];

            return CertificateVariableRegistry::isKnown($key) ? $key : null;
        }

        return null;
    }

    /**
     * @param  list<mixed>  $elements
     */
    private static function inferStudentNameIndex(array $elements): ?int
    {
        $best = null;
        $bestSize = -1.0;

        foreach ($elements as $index => $element) {
            if (! is_array($element) || ($element['type'] ?? 'text') !== 'text') {
                continue;
            }
            if (self::declaredField($element) !== null) {
                continue;
            }
            if (! self::looksLikePersonName((string) ($element['content'] ?? ''))) {
                continue;
            }

            $size = (float) ($element['font_size'] ?? 0);
            if ($size > $bestSize) {
                $bestSize = $size;
                $best = $index;
            }
        }

        return $best;
    }

    public static function looksLikeHeading(string $content): bool
    {
        $normalized = self::normalizePhrase($content);
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::HEADING_PHRASES, true)) {
            return true;
        }

        return str_starts_with($normalized, 'HAS COMPLETED')
            || str_starts_with($normalized, 'THIS IS TO CERTIFY')
            || str_starts_with($normalized, 'THIS CERTIFICATE AWARDED');
    }

    public static function looksLikePersonName(string $content): bool
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
        if ($trimmed === '' || self::looksLikeHeading($trimmed)) {
            return false;
        }
        if (str_contains($trimmed, '{{')) {
            return false;
        }
        if (preg_match('/\d{2,}/', $trimmed) === 1) {
            return false;
        }

        $words = preg_split('/\s+/u', $trimmed) ?: [];
        if (count($words) < 1 || count($words) > 6) {
            return false;
        }

        foreach ($words as $word) {
            if (preg_match('/^[\p{L}\p{M}\'\-]+$/u', $word) !== 1) {
                return false;
            }
        }

        $normalized = self::normalizePhrase($trimmed);

        return ! in_array($normalized, ['SCIENCE STREET FOUNDER', 'شارع العلوم'], true);
    }

    private static function normalizePhrase(string $content): string
    {
        $content = strtoupper(trim(preg_replace('/\s+/u', ' ', $content) ?? ''));
        $content = str_replace(['!', '.', ','], '', $content);

        return $content;
    }
}
