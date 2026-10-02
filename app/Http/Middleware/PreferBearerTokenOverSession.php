<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth for SHARED_SESSION_GUARD_BUG.
 *
 * Primary fix is config/sanctum.php `guard => []` (Bearer-only for auth:sanctum).
 * This middleware remains so any future reintroduction of a web sanctum guard
 * still forgets an in-memory web user when a Bearer token is present.
 *
 * Note: SessionGuard may reload from session after forgetUser(); do not rely on
 * this middleware alone — sanctum.guard must stay empty for production SPA.
 */
final class PreferBearerTokenOverSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! is_string($bearer) || $bearer === '') {
            return $next($request);
        }

        // Drop session user so a later auth:sanctum / OptionalSanctumAuth path
        // cannot keep serving the Filament admin from the shared-host cookie.
        Auth::guard('web')->forgetUser();

        $user = Auth::guard('sanctum')->setRequest($request)->user();
        if ($user !== null) {
            Auth::setUser($user);
            $request->setUserResolver(static fn () => $user);
        }

        return $next($request);
    }
}
