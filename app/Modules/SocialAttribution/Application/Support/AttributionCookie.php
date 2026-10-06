<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class AttributionCookie
{
    public function name(): string
    {
        return (string) config('social_attribution.cookie.name', 'ssl_attr_sid');
    }

    public function read(Request $request): ?string
    {
        $raw = $request->cookie($this->name());
        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    public function mint(): string
    {
        return (string) Str::uuid();
    }

    public function make(string $visitorKey): Cookie
    {
        $days = max(1, (int) config('social_attribution.window_days', 30));
        $secureConfig = config('social_attribution.cookie.secure');
        $secure = is_bool($secureConfig)
            ? $secureConfig
            : app()->environment('production');

        return cookie(
            name: $this->name(),
            value: $visitorKey,
            minutes: $days * 24 * 60,
            path: (string) config('social_attribution.cookie.path', '/'),
            domain: null,
            secure: $secure,
            httpOnly: (bool) config('social_attribution.cookie.http_only', true),
            raw: false,
            sameSite: (string) config('social_attribution.cookie.same_site', 'lax'),
        );
    }
}
