<?php

declare(strict_types=1);

return [
    'api_key' => env('MYFATOORAH_API_KEY'),
    'base_url' => env('MYFATOORAH_BASE_URL', 'https://apitest.myfatoorah.com'),
    'currency' => env('MYFATOORAH_CURRENCY', 'EGP'),
    'language' => env('MYFATOORAH_LANGUAGE', 'AR'),
];
