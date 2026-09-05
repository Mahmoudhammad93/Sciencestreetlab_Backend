<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Assets\Js;
use Illuminate\Support\Facades\Vite;
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
        FilamentAsset::register([
    Js::make('bunny-upload', Vite::asset('resources/js/bunny-upload.js')),
]);
        }
}
