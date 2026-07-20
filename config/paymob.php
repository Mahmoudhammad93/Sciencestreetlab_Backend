<?php

declare(strict_types=1);

return [

    'api_key' => env('PAYMOB_API_KEY'),
    'integration_id' => env('PAYMOB_INTEGRATION_ID'),
    'iframe_id' => env('PAYMOB_IFRAME_ID'),
    'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
    'currency' => env('PAYMOB_CURRENCY', 'EGP'),
    'iframe_url' => env('PAYMOB_IFRAME_URL', 'https://accept.paymob.com/api/acceptance/iframes/'),
    'callback_url' => env('APP_URL').'/api/v1/payments/paymob/callback',

];