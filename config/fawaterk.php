<?php

declare(strict_types=1);

return [
    /**
     * Base API URL.
     * Staging: https://staging.fawaterk.com
     * Live:    https://app.fawaterk.com
     */
    'base_url' => env('FAWATERAK_BASE_URL', 'https://staging.fawaterk.com'),

    /**
     * Bearer token from: Fawaterak dashboard > Integrations > Fawaterak > API Key.
     */
    'api_key' => env('FAWATERAK_API_KEY'),

    /**
     * "Vendor key" used to verify the HMAC-SHA256 hashKey Fawaterak sends
     * with webhooks (paid / failed / cancel) and with the IFrame hash.
     * Same value as the API Key in the dashboard unless Fawaterak gives you
     * a separate secret for this.
     */
    'vendor_key' => env('FAWATERAK_VENDOR_KEY'),

    'currency' => env('FAWATERAK_CURRENCY', 'EGP'),
];