<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Centralized admin locale + document direction for Filament.
 *
 * Filament v3 reads __('filament-panels::layout.direction') which is
 * "rtl" for ar and "ltr" for en — so setting the app locale is enough
 * for sidebar/content direction when Filament lang packs are loaded.
 */
final class AdminLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public const SESSION_KEY = 'admin_locale';

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));
        $locale = explode('-', explode('_', $locale)[0])[0];

        return in_array($locale, self::SUPPORTED, true)
            ? $locale
            : (string) config('app.locale', 'en');
    }

    public static function current(): string
    {
        return self::normalize(app()->getLocale());
    }

    public static function direction(?string $locale = null): string
    {
        return self::normalize($locale ?? self::current()) === 'ar' ? 'rtl' : 'ltr';
    }

    public static function isRtl(?string $locale = null): bool
    {
        return self::direction($locale) === 'rtl';
    }

    /**
     * Resolve locale for an admin request without affecting the public API
     * Accept-Language middleware.
     */
    public static function resolveForAdmin(?\Illuminate\Contracts\Auth\Authenticatable $user = null): string
    {
        if (session()->has(self::SESSION_KEY)) {
            return self::normalize((string) session(self::SESSION_KEY));
        }

        if ($user !== null && isset($user->locale) && filled($user->locale)) {
            return self::normalize((string) $user->locale);
        }

        return self::normalize((string) config('app.locale', 'en'));
    }

    public static function persist(string $locale, ?\Illuminate\Contracts\Auth\Authenticatable $user = null): string
    {
        $locale = self::normalize($locale);
        session([self::SESSION_KEY => $locale]);

        if ($user !== null && method_exists($user, 'forceFill')) {
            try {
                $user->forceFill(['locale' => $locale])->save();
            } catch (\Throwable) {
                // Preference may fail if column missing in edge environments — session still wins.
            }
        }

        app()->setLocale($locale);

        return $locale;
    }
}
