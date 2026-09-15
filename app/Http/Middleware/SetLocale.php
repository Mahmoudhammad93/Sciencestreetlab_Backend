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
        $locale = $request->header('Accept-Language');

        // Only allow supported locales
        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = config('app.locale', 'ar');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}