<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

final class ErrorIncidentDisplay
{
    public function __construct(
        private readonly ErrorPathNormalizer $paths,
        private readonly ErrorRedactor $redactor,
    ) {}

    public function text(mixed $value, string $empty = 'N/A'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }

        return $empty;
    }

    public function classBasename(mixed $value): string
    {
        $text = $this->text($value);
        if ($text === 'N/A') {
            return $text;
        }

        return class_basename($text);
    }

    public function file(mixed $value): string
    {
        $text = $this->text($value);
        if ($text === 'N/A') {
            return $text;
        }
        $normalized = $this->paths->normalize($text);

        return is_string($normalized) && $normalized !== '' ? $normalized : $text;
    }

    public function userType(mixed $value): string
    {
        return match (is_string($value) ? $value : '') {
            'customer' => (string) __('admin.error_incidents.user_types.customer'),
            'admin' => (string) __('admin.error_incidents.user_types.admin'),
            'system' => (string) __('admin.error_incidents.user_types.system'),
            'guest' => (string) __('admin.error_incidents.user_types.guest'),
            default => (string) __('admin.error_incidents.user_types.guest'),
        };
    }

    public function context(mixed $value): string
    {
        $normalized = $this->normalizeContext($value);
        if ($normalized === []) {
            return 'N/A';
        }
        $redacted = $this->redactor->redact($normalized);
        $json = json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) && $json !== '' ? $json : 'N/A';
    }

    public function trace(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'N/A';
        }
        if (is_array($value)) {
            return $this->context($value);
        }
        if (! is_string($value)) {
            return 'N/A';
        }
        $trim = trim($value);
        if ($trim === '') {
            return 'N/A';
        }
        if ($trim[0] === '{' || $trim[0] === '[') {
            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->context($decoded);
            }
        }

        return $this->redactor->sanitizeStack($value, (int) config('error_monitoring.trace.max_chars', 16000));
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeContext(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return ['_unparsed' => mb_substr($value, 0, 4000)];
        }
        if (is_object($value)) {
            return ['_class' => $value::class];
        }

        return ['_value' => $this->text($value)];
    }
}
