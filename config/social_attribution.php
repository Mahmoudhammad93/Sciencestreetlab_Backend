<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Social Attribution (first-party, provider-agnostic)
    |--------------------------------------------------------------------------
    |
    | Internal click → session → order attribution foundation.
    | No Meta Pixel / CAPI configuration lives here.
    |
    */

    'window_days' => (int) env('SOCIAL_ATTRIBUTION_WINDOW_DAYS', 30),

    'cookie' => [
        // No dots: PHP maps cookie name dots to underscores in $_COOKIE.
        'name' => env('SOCIAL_ATTRIBUTION_COOKIE', 'ssl_attr_sid'),
        'path' => '/',
        'same_site' => 'lax',
        // Secure is forced when app.env=production unless overridden.
        'secure' => env('SOCIAL_ATTRIBUTION_COOKIE_SECURE'),
        'http_only' => true,
    ],

    /*
    | Pepper for hashing client IP / user-agent at rest.
    | Prefer a dedicated secret; falls back to APP_KEY.
    */
    'hash_pepper' => env('SOCIAL_ATTRIBUTION_HASH_PEPPER', env('APP_KEY')),

    /*
    | Allowed relative destination path prefixes (leading slash, no host).
    | Matches current SPA routes: /shop, /courses, /microscope-landing-page.
    */
    'allowed_destination_prefixes' => [
        '/shop',
        '/courses',
        '/microscope-landing-page',
        '/cart',
        '/checkout',
    ],

    'allowed_exact_paths' => [
        '/microscope-landing-page',
        '/shop',
        '/courses',
        '/cart',
        '/checkout',
    ],

    'attribution_model' => 'last_touch_v1',

    /*
    | Click retention documentation only — no automatic destructive cleanup.
    */
    'click_retention_days' => (int) env('SOCIAL_ATTRIBUTION_CLICK_RETENTION_DAYS', 400),

    'bot_user_agent_substrings' => [
        'facebookexternalhit',
        'facebot',
        'meta-externalagent',
        'twitterbot',
        'linkedinbot',
        'slackbot',
        'discordbot',
        'whatsapp',
        'telegrambot',
        'googlebot',
        'bingbot',
        'yandexbot',
        'baiduspider',
        'duckduckbot',
        'applebot',
        'semrushbot',
        'ahrefsbot',
        'petalbot',
        'bytespider',
        'gptbot',
        'preview',
    ],
];
