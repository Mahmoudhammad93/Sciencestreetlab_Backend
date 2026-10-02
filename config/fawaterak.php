<?php

declare(strict_types=1);

return [
    'base_url' => env('FAWATERAK_BASE_URL', 'https://staging.fawaterk.com'),
    'api_key' => env('FAWATERAK_API_KEY'),
    'vendor_key' => env('FAWATERAK_VENDOR_KEY'),
    'currency' => env('FAWATERAK_CURRENCY', 'EGP'),
    // Live Fawaterak rejects invoices at or below this amount (EGP).
    'min_amount' => (float) env('FAWATERAK_MIN_AMOUNT', 5.01),
];
