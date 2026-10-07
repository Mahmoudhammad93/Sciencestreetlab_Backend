<?php

declare(strict_types=1);

return [

    'enabled' => (bool) env('ERROR_MONITORING_ENABLED', true),

    /*
     * Resolved incidents older than this many days may be pruned.
     * Open incidents are never deleted by retention.
     */
    'retention_days' => (int) env('ERROR_MONITORING_RETENTION_DAYS', 90),

    'fingerprint' => [
        'hash_algo' => 'sha1',
    ],

    'trace' => [
        'max_frames' => 40,
        'max_chars' => 16000,
    ],

    'message' => [
        'max_chars' => 2000,
    ],

    'ignore_http_statuses' => [401, 403, 404, 419, 422, 429],

    'frontend' => [
        'max_message' => 500,
        'max_stack' => 8000,
        'max_pathname' => 500,
        'rate_limit_per_minute' => 20,
    ],

];
