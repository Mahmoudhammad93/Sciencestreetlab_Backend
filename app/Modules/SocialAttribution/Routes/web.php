<?php

declare(strict_types=1);

use App\Modules\SocialAttribution\Http\Controllers\TrackingRedirectController;
use Illuminate\Support\Facades\Route;

/*
| Public first-party tracking redirect.
| Must be served by Laravel (nginx /go/ → PHP) — not the SPA proxy.
*/
Route::get('/go/{code}', TrackingRedirectController::class)
    ->where('code', '[A-Za-z0-9][A-Za-z0-9_-]{1,63}')
    ->middleware('throttle:60,1')
    ->name('social-attribution.go');
