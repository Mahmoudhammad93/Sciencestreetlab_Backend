<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a Bearer token is present, ignore the web session user for this request.
 *
 * Filament admin and the customer SPA share app.sciencestreetlab.com. Sanctum's
 * guard checks the web session BEFORE the Bearer token, so an active /admin
 * session would otherwise make /api/* resolve as the admin even when the SPA
 * sends a customer token — leaking admin profile/orders/enrollments.
 */
final class PreferBearerTokenOverSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (is_string($request->bearerToken()) && $request->bearerToken() !== '') {
            Auth::guard('web')->forgetUser();
        }

        return $next($request);
    }
}
