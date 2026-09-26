<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdminLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies persisted admin locale for Filament panel requests only.
 * Does not alter API Accept-Language handling (SetLocale).
 */
final class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = AdminLocale::resolveForAdmin($request->user());
        app()->setLocale($locale);

        return $next($request);
    }
}
