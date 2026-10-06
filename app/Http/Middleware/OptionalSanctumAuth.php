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

        // Storefront API is Bearer-only. A Filament admin cookie on the same host
        // must never own guest cart/checkout when no customer Bearer is present.
        if (! is_string($bearer) || $bearer === '') {
            Auth::guard('web')->forgetUser();

            // Keep Sanctum::actingAs / already-resolved sanctum identities (tests + token guards).
            $sanctumUser = Auth::guard('sanctum')->user();
            if ($sanctumUser !== null) {
                Auth::setUser($sanctumUser);
                $request->setUserResolver(static fn () => $sanctumUser);
            } else {
                $request->setUserResolver(static fn () => null);
            }

            return $next($request);
        }

        // Drop any in-memory web/session user so Sanctum resolves the token.
        Auth::guard('web')->forgetUser();

        $user = Auth::guard('sanctum')->setRequest($request)->user();
        if ($user !== null) {
            Auth::setUser($user);
            $request->setUserResolver(static fn () => $user);
        } else {
            $request->setUserResolver(static fn () => null);
        }

        return $next($request);
    }
}
