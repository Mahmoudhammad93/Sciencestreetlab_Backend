<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Sanctum bearer tokens when present, without requiring auth.
 */
final class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() && $request->bearerToken()) {
            $user = Auth::guard('sanctum')->setRequest($request)->user();

            if ($user) {
                Auth::setUser($user);
            }
        }

        return $next($request);
    }
}
