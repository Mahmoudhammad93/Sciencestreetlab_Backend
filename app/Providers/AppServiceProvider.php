<?php

namespace App\Providers;

use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('auth-password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-verification-resend', fn (Request $request) => Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));

        // Bunny upload asset is only available after `npm run build` (or `npm run dev`).
        if (is_file(public_path('build/manifest.json'))) {
            FilamentAsset::register([
                Js::make('bunny-upload', Vite::asset('resources/js/bunny-upload.js')),
            ]);
        }
    }
}
