<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sales Channels / Social Commerce
    |--------------------------------------------------------------------------
    |
    | Google Merchant uses official Merchant API + service-account JWT auth
    | (scope https://www.googleapis.com/auth/content). Endpoints below are from
    | Google Merchant API docs — do not invent alternatives.
    |
    */

    'google_merchant' => [
        'enabled' => (bool) env('SALES_CHANNEL_GOOGLE_MERCHANT_ENABLED', true),

        // Official OAuth scope required by Merchant API methods.
        'scope' => 'https://www.googleapis.com/auth/content',

        // Official OAuth 2.0 token endpoint for service-account JWT bearer grants.
        'token_uri' => 'https://oauth2.googleapis.com/token',

        // Official Merchant API base URLs (Accounts / Products / Data Sources sub-APIs).
        'accounts_base_url' => 'https://merchantapi.googleapis.com/accounts/v1',
        'products_base_url' => 'https://merchantapi.googleapis.com/products/v1',
        'datasources_base_url' => 'https://merchantapi.googleapis.com/datasources/v1',

        // Defaults for ScienceStreetLab Egypt storefront product inputs.
        'content_language' => env('SALES_CHANNEL_GOOGLE_CONTENT_LANGUAGE', 'en'),
        'feed_label' => env('SALES_CHANNEL_GOOGLE_FEED_LABEL', 'EG'),
        'primary_country' => env('SALES_CHANNEL_GOOGLE_PRIMARY_COUNTRY', 'EG'),
        'data_source_display_name' => env('SALES_CHANNEL_GOOGLE_DATA_SOURCE_NAME', 'ScienceStreetLab API Primary'),
    ],

    'youtube_shopping' => [
        // YouTube Shopping is not a direct API twin of Merchant — eligibility is separate.
        'enabled' => (bool) env('SALES_CHANNEL_YOUTUBE_ENABLED', true),
        'eligibility_confirmed' => (bool) env('SALES_CHANNEL_YOUTUBE_ELIGIBILITY_CONFIRMED', false),
        'store_linked' => (bool) env('SALES_CHANNEL_YOUTUBE_STORE_LINKED', false),
    ],

];
