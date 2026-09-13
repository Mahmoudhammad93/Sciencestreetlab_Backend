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

    /*
     * Optional. Placeholders: {frontend}, {order_number}, {order_id}.
     * Defaults to {frontend}/orders/{order_number}.
     */
    'order_url_template' => env('FRONTEND_ORDER_URL'),

    /*
     * Public page opened by the enrollment QR code.
     * Placeholders: {frontend}, {token}.
     * Defaults to {frontend}/verify/enrollment/{token}.
     */
    'enrollment_verification_url_template' => env('FRONTEND_ENROLLMENT_VERIFICATION_URL'),

];
