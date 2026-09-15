<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->header('Accept-Language', '');
        $locale = $this->resolveLocale($raw);

        app()->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(string $raw): string
    {
        if ($raw === '') {
            return (string) config('app.locale', 'ar');
        }

        // Accept "en", "en-US", "ar,en;q=0.8" style headers.
        $primary = strtolower(trim(explode(',', $raw)[0]));
        $primary = trim(explode(';', $primary)[0]);
        $tag = explode('-', $primary)[0];
        $tag = explode('_', $tag)[0];

        if (in_array($tag, ['ar', 'en'], true)) {
            return $tag;
        }

        return (string) config('app.locale', 'ar');
    }
}
