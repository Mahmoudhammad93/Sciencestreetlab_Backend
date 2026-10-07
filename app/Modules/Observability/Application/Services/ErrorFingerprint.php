<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

final class ErrorFingerprint
{
    public function normalizeMessage(string $message): string
    {
        $normalized = $message;
        $normalized = preg_replace('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', '{uuid}', $normalized) ?? $normalized;
        $normalized = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '{email}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?/', '{date}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b[a-fA-F0-9]{32,}\b/', '{hex}', $normalized) ?? $normalized;
        $normalized = preg_replace('#/(?:\d+)(?=/|$)#', '/{id}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bSS-[A-Z0-9]{6,}\b/', '{order}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\buser(?:_id)?\s*[:=#]?\s*\d+\b/i', 'user {id}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d{8,}\b/', '{num}', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param  array{
     *     exception_class: string,
     *     message: string,
     *     file: ?string,
     *     line: ?int,
     *     route_name: ?string,
     *     module: ?string,
     *     source: string
     * }  $parts
     */
    public function make(array $parts): string
    {
        $payload = implode('|', [
            $parts['exception_class'],
            $this->normalizeMessage($parts['message']),
            (string) ($parts['file'] ?? ''),
            (string) ($parts['line'] ?? ''),
            (string) ($parts['route_name'] ?? ''),
            (string) ($parts['module'] ?? ''),
            $parts['source'],
        ]);

        return hash('sha1', $payload);
    }
}
