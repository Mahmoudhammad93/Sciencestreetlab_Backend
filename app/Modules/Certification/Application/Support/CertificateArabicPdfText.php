<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

/**
 * DomPDF does not shape Arabic. Presentation-form glyphs are applied only for PDF.
 * HTML preview keeps the original Unicode so the browser can shape natively.
 */
final class CertificateArabicPdfText
{
    public static function containsArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }

    public static function shapeForPdf(string $text): string
    {
        if ($text === '' || ! self::containsArabic($text)) {
            return $text;
        }

        if (! class_exists(\ArPHP\I18N\Arabic::class)) {
            return $text;
        }

        $arabic = new \ArPHP\I18N\Arabic();

        return $arabic->utf8Glyphs($text, 200, false);
    }

    /**
     * Field-aware direction. English headings stay LTR; Arabic runs become RTL
     * unless the field is an identifier that must remain LTR.
     *
     * @param  array<string, mixed>  $element
     */
    public static function resolveDirection(array $element, string $resolvedText): string
    {
        $field = CertificateDynamicFieldBinder::declaredField($element);
        if (in_array($field, ['certificate_number', 'completion_date', 'issue_date'], true)) {
            return 'ltr';
        }

        $declared = strtolower((string) ($element['dir'] ?? ''));
        if ($declared === 'rtl' || $declared === 'ltr') {
            return $declared;
        }

        return self::containsArabic($resolvedText) ? 'rtl' : 'ltr';
    }
}
