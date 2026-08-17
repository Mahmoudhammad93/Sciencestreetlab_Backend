<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');

/*
|--------------------------------------------------------------------------
| WordPress → React SEO redirects (301)
|--------------------------------------------------------------------------
*/
$redirects = [
    '/shop' => '/shop',
    '/shop/' => '/shop',
    '/cart' => '/cart',
    '/cart/' => '/cart',
    '/checkout' => '/checkout',
    '/checkout/' => '/checkout',
    '/login' => '/login',
    '/login/' => '/login',
    '/my-account' => '/account',
    '/my-account/' => '/account',
    '/school_courses' => '/school/courses',
    '/school_courses/' => '/school/courses',
    '/microscope_challenge' => '/competition/microscope-100-challenge',
    '/microscope_challenge/' => '/competition/microscope-100-challenge',
    '/quest-dashboard' => '/competition/microscope-100-challenge/dashboard',
    '/quest-dashboard/' => '/competition/microscope-100-challenge/dashboard',
    '/quest-upload' => '/competition/microscope-100-challenge/upload',
    '/quest-upload/' => '/competition/microscope-100-challenge/upload',
    '/item/science-street-microscope' => '/shop/science-street-microscope',
    '/item/science-street-microscope/' => '/shop/science-street-microscope',
];

foreach ($redirects as $from => $to) {
    Route::get($from, fn () => redirect()->away("{$frontend}{$to}", 301));
}

Route::get('/', fn () => redirect()->away($frontend, 301));

/*
|--------------------------------------------------------------------------
| Sandboxed interactive question assets (no Sanctum session)
|--------------------------------------------------------------------------
| Load inside <iframe sandbox="allow-scripts"> from a dedicated origin
| when possible. Communicates via window.parent.postMessage only.
*/
Route::get('/interactive/{uuid}/{path?}', [\App\Modules\Assessment\Http\Controllers\InteractiveActivityController::class, 'show'])
    ->where('path', '.*')
    ->name('interactive.activity');

Route::get('/interactive-activities/{uuid}/v{version}/{path?}', [\App\Modules\Assessment\Http\Controllers\InteractiveActivityController::class, 'showPackage'])
    ->where('path', '.*')
    ->name('interactive.activity.package');

// SPA fallback for Laravel-served preview (optional)
Route::get('/app/{any?}', function () {
    return view('spa');
})->where('any', '.*');
