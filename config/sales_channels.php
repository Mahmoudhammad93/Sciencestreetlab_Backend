<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sales Channels / Social Commerce
    |--------------------------------------------------------------------------
    |
    | Provider credentials and API contracts are intentionally gated.
    | Do not invent Google/YouTube OAuth scopes or Merchant endpoints here.
    | Flip enabled + api_contract_ready only after official credentials/docs.
    |
    */

    'google_merchant' => [
        'enabled' => (bool) env('SALES_CHANNEL_GOOGLE_MERCHANT_ENABLED', false),
        'client_id' => env('SALES_CHANNEL_GOOGLE_CLIENT_ID'),
        'client_secret' => env('SALES_CHANNEL_GOOGLE_CLIENT_SECRET'),
        'api_contract_ready' => (bool) env('SALES_CHANNEL_GOOGLE_API_CONTRACT_READY', false),
    ],

    'youtube_shopping' => [
        'enabled' => (bool) env('SALES_CHANNEL_YOUTUBE_ENABLED', false),
        'client_id' => env('SALES_CHANNEL_YOUTUBE_CLIENT_ID', env('SALES_CHANNEL_GOOGLE_CLIENT_ID')),
        'client_secret' => env('SALES_CHANNEL_YOUTUBE_CLIENT_SECRET', env('SALES_CHANNEL_GOOGLE_CLIENT_SECRET')),
        'api_contract_ready' => (bool) env('SALES_CHANNEL_YOUTUBE_API_CONTRACT_READY', false),
    ],

];
