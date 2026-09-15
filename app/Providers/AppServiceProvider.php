<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
        $this->configurePublicFrontendUrl();
        $this->configureBrevoMailer();

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('auth-password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-verification-resend', fn (Request $request) => Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));

        // Admin interactive HTML packages can be several MB. Keep Livewire's
        // temporary upload limit in sync with docker/php/uploads.ini.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:51200'],
            'livewire.temporary_file_upload.max_upload_time' => 5,
        ]);

        // Bunny upload asset is only available after `npm run build` (or `npm run dev`).
        if (is_file(public_path('build/manifest.json'))) {
            FilamentAsset::register([
                Js::make('bunny-upload', Vite::asset('resources/js/bunny-upload.js')),
            ]);
        }
    }

    /**
     * Queued mail reads FRONTEND_URL from the container environment. If that
     * value is still the local Vite default but APP_URL is the public site,
     * password-reset and order links must use the public site.
     */
    private function configurePublicFrontendUrl(): void
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');
        $appUrl = rtrim((string) config('app.url'), '/');

        if (! $this->isLoopbackUrl($frontend) || $this->isLoopbackUrl($appUrl)) {
            return;
        }

        config(['sciencestreet.frontend_url' => $appUrl]);
    }

    private function isLoopbackUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return ! is_string($host) || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Use Brevo when an API key is present. The key may live in env or in an
     * untracked storage file so it never has to be committed.
     */
    private function configureBrevoMailer(): void
    {
        $key = $this->brevoApiKey();

        Mail::extend('brevo', function () use ($key) {
            return new BrevoTransport($key ?? (string) config('services.brevo.key', ''));
        });

        if ($key === null || $key === '') {
            return;
        }

        config(['services.brevo.key' => $key]);

        // Never force Brevo during automated tests — keep MAIL_MAILER=array.
        if ($this->app->environment('testing')) {
            return;
        }

        $default = (string) config('mail.default');

        if (in_array($default, ['', 'log', 'array'], true)) {
            config(['mail.default' => 'brevo']);
        }

        $from = (string) config('mail.from.address');

        if ($from === '' || $from === 'hello@example.com') {
            config(['mail.from.address' => 'noreply@sciencestreetlab.com']);
        }

        $name = (string) config('mail.from.name');

        if ($name === '' || str_contains($name, '${')) {
            config(['mail.from.name' => (string) config('sciencestreet.name', 'Science Street Lab')]);
        }
    }

    private function brevoApiKey(): ?string
    {
        $fromEnv = config('services.brevo.key');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $path = storage_path('app/private/brevo.key');

        if (! is_file($path)) {
            return null;
        }

        $key = trim((string) file_get_contents($path));

        return $key !== '' ? $key : null;
    }
}
