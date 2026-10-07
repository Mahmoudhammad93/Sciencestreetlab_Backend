<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

/**
 * Controlled whitelist of certificate placeholders.
 * Admin template content may only reference these keys.
 */
final class CertificateVariableRegistry
{
    /** @return array<string, string> key => human label */
    public static function definitions(): array
    {
        return [
            'student_name' => 'Student name',
            'course_name' => 'Course / camp name',
            'certificate_title' => 'Certificate title',
            'certificate_subtitle' => 'Certificate subtitle',
            'intro_text' => 'Intro text',
            'achievement_text' => 'Achievement / completion text',
            'completion_date' => 'Completion date',
            'issue_date' => 'Issue date',
            'certificate_number' => 'Certificate number',
            'signer_name' => 'Signer name',
            'signer_title' => 'Signer title',
            'verification_url' => 'Verification URL',
            'brand_name' => 'Brand name',
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /**
     * Replace {{ var }} tokens. Unknown keys become empty string.
     *
     * @param  array<string, string|null>  $values
     */
    public static function resolve(string $content, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u',
            function (array $m) use ($values): string {
                $key = $m[1];
                if (! self::isKnown($key)) {
                    return '';
                }

                $value = $values[$key] ?? '';

                return is_string($value) ? $value : '';
            },
            $content
        );
    }

    public static function helperText(): string
    {
        $lines = [];
        foreach (self::definitions() as $key => $label) {
            $lines[] = '{{ '.$key.' }} — '.$label;
        }

        return implode("\n", $lines);
    }
}
