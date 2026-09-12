<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/** Resolve stored image paths (relative or absolute) to public URLs. */
final class PublicMediaUrl
{
    public static function make(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return Storage::disk('public')->url(ltrim($value, '/'));
    }

    public static function toDiskPath(mixed $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        if (is_array($state)) {
            $state = Arr::first($state);
        }

        if (blank($state)) {
            return null;
        }

        $state = (string) $state;

        if (! str_starts_with($state, 'http://') && ! str_starts_with($state, 'https://')) {
            return ltrim($state, '/');
        }

        $publicBase = rtrim((string) Storage::disk('public')->url(''), '/');
        if (str_starts_with($state, $publicBase.'/')) {
            return ltrim(substr($state, strlen($publicBase) + 1), '/');
        }

        if (preg_match('#/storage/(.+)$#', $state, $matches) === 1) {
            return ltrim($matches[1], '/');
        }

        return null;
    }

    public static function normalizeStoredValue(mixed $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        if (is_array($state)) {
            $state = Arr::first($state);
        }

        if (blank($state)) {
            return null;
        }

        $state = (string) $state;

        if (str_starts_with($state, 'http://') || str_starts_with($state, 'https://')) {
            return $state;
        }

        return ltrim($state, '/');
    }
}
