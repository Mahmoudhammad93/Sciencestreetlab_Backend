<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services;

use InvalidArgumentException;

/**
 * Same-origin relative path allowlist for tracking redirects.
 * Explicit domain mapping — not Eloquent morphs from user input.
 */
final class DestinationAllowlist
{
    /**
     * Normalize and validate a relative internal path.
     *
     * @throws InvalidArgumentException
     */
    public function assertSafePath(string $path): string
    {
        $normalized = $this->normalize($path);

        if (! $this->isAllowed($normalized)) {
            throw new InvalidArgumentException('Destination path is not allowlisted.');
        }

        return $normalized;
    }

    public function isAllowed(string $path): bool
    {
        try {
            $normalized = $this->normalize($path);
        } catch (InvalidArgumentException) {
            return false;
        }

        /** @var list<string> $exact */
        $exact = config('social_attribution.allowed_exact_paths', []);
        if (in_array($normalized, $exact, true)) {
            return true;
        }

        /** @var list<string> $prefixes */
        $prefixes = config('social_attribution.allowed_destination_prefixes', []);
        foreach ($prefixes as $prefix) {
            $prefix = rtrim($prefix, '/') ?: '/';
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function normalize(string $path): string
    {
        $raw = trim($path);
        if ($raw === '') {
            throw new InvalidArgumentException('Destination path is empty.');
        }

        // Decode repeatedly to catch double-encoding tricks.
        $decoded = $raw;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $lower = strtolower($decoded);
        if (
            str_contains($lower, 'javascript:')
            || str_contains($lower, 'data:')
            || str_contains($lower, 'vbscript:')
            || str_contains($decoded, '\\')
        ) {
            throw new InvalidArgumentException('Destination scheme is not allowed.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $decoded) === 1) {
            throw new InvalidArgumentException('Absolute URLs are not allowed.');
        }

        if (str_starts_with($decoded, '//')) {
            throw new InvalidArgumentException('Protocol-relative URLs are not allowed.');
        }

        if (str_contains($decoded, '@')) {
            throw new InvalidArgumentException('Userinfo destinations are not allowed.');
        }

        // Strip query/fragment for allowlist matching; redirect builder re-applies safe query.
        $pathOnly = explode('#', explode('?', $decoded, 2)[0], 2)[0];
        if (! str_starts_with($pathOnly, '/')) {
            $pathOnly = '/'.$pathOnly;
        }

        // Collapse dot segments without allowing escape.
        $parts = [];
        foreach (explode('/', $pathOnly) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Path traversal is not allowed.');
            }
            $parts[] = $segment;
        }

        $normalized = '/'.implode('/', $parts);
        if ($normalized !== '/' && str_ends_with($pathOnly, '/')) {
            // Keep trailing slash only for root-like exact paths we don't need; strip.
            $normalized = rtrim($normalized, '/') ?: '/';
        }

        return $normalized === '' ? '/' : $normalized;
    }
}
