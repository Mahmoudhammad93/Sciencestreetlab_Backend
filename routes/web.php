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

// SPA fallback for Laravel-served preview (optional)
Route::get('/app/{any?}', function () {
    return view('spa');
})->where('any', '.*');
