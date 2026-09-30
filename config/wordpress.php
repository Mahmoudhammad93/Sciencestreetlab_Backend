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

    /*
    |--------------------------------------------------------------------------
    | Product SKU migration strategy (APPROVED)
    |--------------------------------------------------------------------------
    |
    | Decision: for empty WordPress _sku use deterministic WP-{legacy_product_id}.
    | Genuine non-empty _sku is always preserved.
    |
    | Set WORDPRESS_PRODUCT_SKU_STRATEGY=wp_id to enable real product import.
    | Dry-runs work without the env flag.
    |
    */
    /*
    | Approved: empty WP _sku → WP-{legacy_product_id}. Set env to wp_id for real import.
    | Default null keeps real product import gated; dry-runs work regardless.
    */
    'product_sku_strategy' => env('WORDPRESS_PRODUCT_SKU_STRATEGY'),

    /*
    | Variable product 6912: approved SKIP for first staging run (do not flatten).
    */
    'skip_variable_product_ids' => [6912],

    /*
    |--------------------------------------------------------------------------
    | Approved MAP_EXISTING collisions (STAGING PREPARATION)
    |--------------------------------------------------------------------------
    |
    | Explicit human-approved map-only bridges. Resolve local ids by slug on the
    | target DB. Applying these maps never overwrites commercial/catalog fields
    | and never stamps created_by_migration=true.
    |
    | Course 8507 is HUMAN_APPROVED_FOR_STAGING as MAP_EXISTING + ABSORB_TREE
    | (Option A MAP_EXISTING_AND_IMPORT_CONTENT) — see docs/WORDPRESS-COURSE-8507-IDENTITY.md.
    |
    | Do NOT treat this registry as authorization for a real entity import.
    | Use: php artisan migration:wordpress:apply-approved-collisions --dry-run
    |
    */
    'approved_map_existing' => [
        'products' => [
            [
                'legacy_id' => '6811',
                'local_slug' => 'hydraulic-excavator-arm',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6855',
                'local_slug' => 'reptile-robot',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6867',
                'local_slug' => 'race-car',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6874',
                'local_slug' => 'rowing-boat',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6876',
                'local_slug' => 'brunei-volleyball',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6878',
                'local_slug' => 'space-engine',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6886',
                'local_slug' => 'spinbot',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6887',
                'local_slug' => 'ultrasonic-obstacle-car',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6890',
                'local_slug' => 'brachiosaurus-the-adventurous',
                'decision' => 'MAP_EXISTING',
                'note' => 'SyncSeeder mirror kit; map-only',
            ],
            [
                'legacy_id' => '6910',
                'local_slug' => 'manual-power-generator',
                'decision' => 'MAP_EXISTING',
                'note' => 'Approved simple MAP_EXISTING; keep local SKU/course_id; map-only',
            ],
        ],
        'competitions' => [
            [
                'legacy_id' => '4',
                'local_slug' => 'microscope-100-challenge',
                'decision' => 'MAP_EXISTING',
                'note' => 'AQ 100-photo challenge → existing Laravel competition; rollback unmaps only',
            ],
        ],
        /*
        | Course 8507 — Option A (MAP_EXISTING_AND_IMPORT_CONTENT / ABSORB_TREE):
        | Map shell onto microscope-course; preserve native/seeded lessons; import
        | WP lessons/topics under that course with created_by_migration ownership.
        | Rollback deletes only migration-created children — never the course shell.
        */
        'courses' => [
            [
                'legacy_id' => '8507',
                'local_slug' => 'microscope-course',
                'decision' => 'MAP_EXISTING',
                'tree_policy' => 'ABSORB_TREE',
                'note' => 'Option A: MAP_EXISTING_AND_IMPORT_CONTENT — preserve Course ID + seeded intro; import 44 WP lessons under microscope-course',
            ],
        ],
    ],

    /*
    | Course 8507 identity verdict.
    | Option A (MAP_EXISTING_AND_IMPORT_CONTENT / ABSORB_TREE) + HUMAN_APPROVED_FOR_STAGING.
    */
    'course_8507_verdict' => 'COURSE_8507_MAP_EXISTING_AND_IMPORT_CONTENT_HIGH_CONFIDENCE',

    /*
    | Per-course tree persist overrides (keyed by WP legacy course id).
    | SUPPRESS_TREE = map shell / enrollments only; do not create lessons/topics.
    | ABSORB_TREE   = import WP lessons/topics under mapped course (preserve native).
    */
    'course_tree_policies' => [
        '8507' => 'ABSORB_TREE',
    ],

    /*
    |--------------------------------------------------------------------------
    | Approved course-tree policy (STAGING)
    |--------------------------------------------------------------------------
    */
    'course_tree' => [
        'staging_approved' => true,
        'authoritative' => 'ld_course_steps',
        'fallback_course_ids' => [38266],
        'empty_source_course_ids' => [38568],
        'exclude_orphans' => true,
        'course_level_quiz_policy' => 'COURSE_LEVEL_QUIZ_REQUIRES_MODEL_DECISION',
    ],

    /*
    |--------------------------------------------------------------------------
    | Real persist gates (SAFETY)
    |--------------------------------------------------------------------------
    |
    | Dry-runs always work. Non-dry-run writers for courses / enrollments /
    | competition require an explicit env flag so accidental artisan calls
    | against a connected wordpress_legacy never write.
    |
    | Set WORDPRESS_REAL_PERSIST=1 only for an authorized staging import window.
    |
    */
    'real_persist' => env('WORDPRESS_REAL_PERSIST'),

    /*
    |--------------------------------------------------------------------------
    | AQ competition identity decision (never auto-chosen by importer alone)
    |--------------------------------------------------------------------------
    |
    | MAP_EXISTING — legacy_import_maps → existing Laravel competition (no overwrite)
    | CREATE_NEW   — create Competition via migration-safe persistence
    |
    | Approved default for staging is recorded under approved_map_existing.competitions.
    | Operator must still pass --decision=MAP_EXISTING (or env) for real persist.
    | Optional WORDPRESS_COMPETITION_EXISTING_LOCAL_ID when MAP_EXISTING.
    |
    */
    'competition_decision' => env('WORDPRESS_COMPETITION_DECISION'),
    'competition_existing_local_id' => env('WORDPRESS_COMPETITION_EXISTING_LOCAL_ID'),
];
