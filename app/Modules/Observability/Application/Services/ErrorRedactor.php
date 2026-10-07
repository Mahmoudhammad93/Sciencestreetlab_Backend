<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

final class ErrorRedactor
{
    /** @var list<string> */
    private const EXACT_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'cookies',
        'secret',
        'api_key',
        'apikey',
        'api-key',
        'private_key',
        'privatekey',
        'card',
        'card_number',
        'cardnumber',
        'cvv',
        'cvc',
        'otp',
        'verification_code',
        'remember_token',
        'bearer',
        'webhook_secret',
        'hmac',
        'hmac_secret',
        'vendor_key',
        'fawaterak_api_key',
        'bosta_api_key',
        'google_private_key',
        'meta_token',
        'credit_card',
        'pan',
    ];

    /** @var list<string> */
    private const SUBSTRINGS = [
        'password',
        'token',
        'authorization',
        'cookie',
        'secret',
        'api_key',
        'apikey',
        'private_key',
        'privatekey',
        'otp',
        'cvv',
        'cvc',
        'webhook',
        'hmac',
        'bearer',
    ];

    public function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? $key);
        $lower = strtolower($key);

        if (in_array($lower, self::EXACT_KEYS, true) || in_array($normalized, self::EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SUBSTRINGS as $needle) {
            $compactNeedle = str_replace('_', '', $needle);
            if (str_contains($lower, $needle) || str_contains($normalized, $compactNeedle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['__truncated' => true];
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $name = (string) $key;
            if ($this->isSensitiveKey($name)) {
                $clean[$name] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $clean[$name] = $this->redact($value, $depth + 1);

                continue;
            }

            if (is_object($value)) {
                $clean[$name] = $value::class;

                continue;
            }

            if (is_string($value) && strlen($value) > 500) {
                $clean[$name] = substr($value, 0, 500).'…';

                continue;
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    public function sanitizeStack(string $stack, int $maxChars): string
    {
        $stack = preg_replace('/Bearer\s+[A-Za-z0-9._\-+=\/]+/i', 'Bearer [REDACTED]', $stack) ?? $stack;
        $stack = preg_replace('/(api[_-]?key|access_token|secret|password)\s*[:=]\s*\S+/i', '$1=[REDACTED]', $stack) ?? $stack;

        if (strlen($stack) > $maxChars) {
            return substr($stack, 0, $maxChars)."\n…[truncated]";
        }

        return $stack;
    }
}
