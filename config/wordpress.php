<?php

declare(strict_types=1);

/**
 * WordPress legacy database settings for migration:wordpress:* commands.
 *
 * The optional `wordpress` connection is also registered in config/database.php
 * from these same WORDPRESS_DB_* env keys. Prefer that connection name via
 * DB::connection('wordpress') / Schema::connection('wordpress').
 *
 * Source-specific LearnDash / WooCommerce SQL is BLOCKED_UNTIL_WORDPRESS_DB_DUMP.
 */
return [

    'connection' => 'wordpress',

    'host' => env('WORDPRESS_DB_HOST'),
    'port' => env('WORDPRESS_DB_PORT', '3306'),
    'database' => env('WORDPRESS_DB_DATABASE'),
    'username' => env('WORDPRESS_DB_USERNAME'),
    'password' => env('WORDPRESS_DB_PASSWORD'),
    'prefix' => env('WORDPRESS_DB_PREFIX', 'wp_'),
    'charset' => env('WORDPRESS_DB_CHARSET', 'utf8mb4'),
    'collation' => env('WORDPRESS_DB_COLLATION', 'utf8mb4_unicode_ci'),

    /*
    |--------------------------------------------------------------------------
    | Probe tables (WordPress core only)
    |--------------------------------------------------------------------------
    |
    | Well-known core tables used as optional connectivity probes.
    | Do not invent LearnDash / WooCommerce column mappings here.
    |
    */
    'probe_tables' => [
        'users', // wp_users
        'posts', // wp_posts
    ],

    'blocked_reason' => 'BLOCKED_UNTIL_WORDPRESS_DB_DUMP',

];
