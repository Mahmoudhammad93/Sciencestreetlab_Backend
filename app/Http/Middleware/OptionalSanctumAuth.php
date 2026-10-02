<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Sanctum bearer tokens when present, without requiring auth.
 *
 * CRITICAL: When Authorization: Bearer is present and valid, that identity MUST
 * win over any Filament/web session cookie on the same host. Checkout previously
 * attached orders to admin user 1 because session user was already resolved
 * before this middleware ran, so Bearer was skipped.
 */
final class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! is_string($bearer) || $bearer === '') {
            return $next($request);
        }

        // Drop any in-memory web/session user so Sanctum resolves the token.
        Auth::guard('web')->forgetUser();

        $user = Auth::guard('sanctum')->setRequest($request)->user();
        if ($user !== null) {
            Auth::setUser($user);
            $request->setUserResolver(static fn () => $user);
        }

        return $next($request);
    }
}
