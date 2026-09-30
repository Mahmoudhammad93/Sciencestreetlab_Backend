<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bosta shipping (Egypt)
    |--------------------------------------------------------------------------
    |
    | Real HTTP endpoint paths and webhook signature algorithms are marked
    | BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS until official staging credentials
    | and API documentation are supplied. The integration boundary, shipment
    | persistence, webhook pipeline, and DELIVERED → OrderFulfilled flow are
    | fully implemented and covered by Fake* test doubles.
    |
    */

    'enabled' => filter_var(env('BOSTA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'api_url' => env('BOSTA_API_URL'),

    'api_key' => env('BOSTA_API_KEY'),

    'webhook_secret' => env('BOSTA_WEBHOOK_SECRET'),

    /*
    | When true (default in testing), FakeBostaClient / FakeBostaWebhookVerifier
    | are bound. Set BOSTA_USE_FAKE=true locally/staging without credentials.
    | FORBIDDEN when APP_ENV=production — binding will throw.
    */
    'use_fake' => filter_var(
        env('BOSTA_USE_FAKE', env('APP_ENV') === 'testing' ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    | Flip to true ONLY after official docs confirm create-shipment + webhook
    | contracts and credentials are present. Until then HttpBostaClient refuses
    | production calls with BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS.
    */
    'api_contract_ready' => filter_var(env('BOSTA_API_CONTRACT_READY', false), FILTER_VALIDATE_BOOLEAN),

    'webhook_signature_ready' => filter_var(env('BOSTA_WEBHOOK_SIGNATURE_READY', false), FILTER_VALIDATE_BOOLEAN),
];
