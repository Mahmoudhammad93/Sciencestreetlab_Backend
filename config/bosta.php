<?php

declare(strict_types=1);

/**
 * Normalize BOSTA_API_URL to the official production host base.
 * Accepts either https://app.bosta.co or a legacy .../api/v2 suffix.
 */
$rawApiUrl = trim((string) env('BOSTA_API_URL', 'https://app.bosta.co'));
$apiUrl = rtrim($rawApiUrl, '/');
if ($apiUrl !== '' && str_ends_with(strtolower($apiUrl), '/api/v2')) {
    $apiUrl = substr($apiUrl, 0, -strlen('/api/v2'));
    $apiUrl = rtrim($apiUrl, '/');
}
if ($apiUrl === '') {
    $apiUrl = 'https://app.bosta.co';
}

/*
| API key: prefer BOSTA_API_KEY. Support legacy aliases without exposing values.
*/
$apiKey = trim((string) (
    env('BOSTA_API_KEY')
    ?: env('BOSTA_TOKEN')
    ?: env('BOSTA_API_TOKEN')
    ?: ''
));

return [

    /*
    |--------------------------------------------------------------------------
    | Bosta shipping (Egypt) — official API contract
    |--------------------------------------------------------------------------
    |
    | Official docs:
    | - https://docs.bosta.co/docs/how-to/create-your-first-delivery/
    | - https://docs.bosta.co/docs/how-to/get-your-api-key/
    | - https://docs.bosta.co/docs/how-to/get-delivery-status-via-webhook/
    |
    | Create delivery: POST {api_url}/api/v2/deliveries?apiVersion=1
    | Auth header: Authorization: <raw API key> (no Bearer unless docs require it)
    | Normal outbound delivery type: 10 (SEND / Deliver)
    |
    */

    'enabled' => filter_var(env('BOSTA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'api_url' => $apiUrl,

    'api_key' => $apiKey !== '' ? $apiKey : null,

    'webhook_secret' => env('BOSTA_WEBHOOK_SECRET'),

    /*
    | Custom authorization header name configured in Bosta dashboard
    | (or per-delivery webhookCustomHeaders). Official docs: optional
    | Authorization Key with a custom name — not an invented HMAC.
    */
    'webhook_auth_header' => env('BOSTA_WEBHOOK_AUTH_HEADER', 'Authorization'),

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
    | Flip to true ONLY after official docs confirm create-delivery + webhook
    | contracts and credentials are present.
    */
    'api_contract_ready' => filter_var(env('BOSTA_API_CONTRACT_READY', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | When true, ConfiguredBostaWebhookVerifier compares the configured
    | header value to BOSTA_WEBHOOK_SECRET via hash_equals.
    | BOSTA_WEBHOOK_SIGNATURE_READY is kept as a legacy alias.
    */
    'webhook_auth_ready' => filter_var(
        env('BOSTA_WEBHOOK_AUTH_READY', env('BOSTA_WEBHOOK_SIGNATURE_READY', false)),
        FILTER_VALIDATE_BOOLEAN
    ),

    // Legacy alias used by older code/tests.
    'webhook_signature_ready' => filter_var(
        env('BOSTA_WEBHOOK_AUTH_READY', env('BOSTA_WEBHOOK_SIGNATURE_READY', false)),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    | Optional per-delivery webhook URL. Account-level dashboard webhook is preferred.
    | Localhost is not reachable by Bosta — leave null for local.
    */
    'webhook_url' => env('BOSTA_WEBHOOK_URL'),

    /*
    | HTTP client behaviour for create delivery / cities lookup.
    */
    'http_timeout_seconds' => (int) env('BOSTA_HTTP_TIMEOUT', 20),
    'http_retries' => (int) env('BOSTA_HTTP_RETRIES', 2),
    'http_retry_sleep_ms' => (int) env('BOSTA_HTTP_RETRY_SLEEP_MS', 250),
];
