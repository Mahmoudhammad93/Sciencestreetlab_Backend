<?php

declare(strict_types=1);

return [

    'name' => env('APP_NAME', 'Science Street Lab'),
    'api_version' => 'v1',
    'default_locale' => 'ar',
    'supported_locales' => ['ar', 'en'],
    'currency' => 'EGP',
    'timezone' => 'Africa/Cairo',

    'modules' => [
        'identity',
        'catalog',
        'commerce',
        'learning',
        'assessment',
        'certification',
        'gamification',
        'competition',
        'content',
        'notification',
        'media',
        'search',
        'mobile',
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

];