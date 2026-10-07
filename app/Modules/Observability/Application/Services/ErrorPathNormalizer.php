<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

final class ErrorPathNormalizer
{
    public function normalize(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        if (! str_starts_with($normalized, '/') && ! preg_match('#^[A-Za-z]:/#', $normalized)) {
            return ltrim($normalized, './');
        }

        $base = str_replace('\\', '/', base_path());

        if (str_starts_with($normalized, $base)) {
            return ltrim(substr($normalized, strlen($base)), '/');
        }

        foreach (['/app/', '/vendor/', '/bootstrap/', '/resources/', '/routes/', '/src/'] as $marker) {
            $pos = strpos($normalized, $marker);
            if ($pos !== false) {
                return ltrim(substr($normalized, $pos), '/');
            }
        }

        return basename($normalized);
    }
}
