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
    | Destination-aware: WordPressApprovedCollisionMapper::*Decision() returns
    | MAP_EXISTING only when the approved local_slug exists on the destination.
    | Absent targets fall through to normal create/collision logic (no phantom maps).
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
        /*
        | C-A APPROVED (WORDPRESS-FINAL-HUMAN-DECISIONS):
        | AQ competition 4 ≡ Laravel competitions.id=1 (transliterated production slug).
        | Do NOT seed microscope-100-challenge. Do NOT create a second competition.
        | Rollback: unmap only — never delete competition id 1 or its participant/submission graph.
        */
        'competitions' => [
            [
                'legacy_id' => '4',
                'local_id' => 1,
                'local_slug' => null,
                'decision' => 'MAP_EXISTING',
                'authoritative_accepted' => true,
                'protect_participant_submission_graph' => true,
                'rollback_policy' => 'unmap_only_never_delete_competition_or_graph',
                'note' => 'C-A: AQ4 → Laravel competition id 1; accept existing CREATE_NEW row; never seed microscope-100-challenge',
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
    | Legacy first-login password upgrade (Option A)
    |--------------------------------------------------------------------------
    |
    | Run-scoped USER maps with password_strategy=reset_required may verify
    | against the READ-ONLY historical WordPress DB on first login, then
    | upgrade users.password to Laravel bcrypt. Never copies WP hashes into
    | users.password. Never writes to the historical DB.
    |
    */
    'legacy_auth' => [
        'migration_run_id' => (int) env('WORDPRESS_LEGACY_AUTH_MIGRATION_RUN_ID', 1),
        'enabled' => filter_var(env('WORDPRESS_LEGACY_AUTH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | AQ competition identity decision (never auto-chosen by importer alone)
    |--------------------------------------------------------------------------
    |
    | MAP_EXISTING — legacy_import_maps → existing Laravel competition (no overwrite)
    | CREATE_NEW   — create Competition via migration-safe persistence
    |
    | C-A approved authority: AQ 4 → local competitions.id=1
    | (see approved_map_existing.competitions). Default env may still override.
    |
    */
    'competition_decision' => env('WORDPRESS_COMPETITION_DECISION', 'MAP_EXISTING'),
    'competition_existing_local_id' => env('WORDPRESS_COMPETITION_EXISTING_LOCAL_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Approved explicit user resolutions (narrow, auditable)
    |--------------------------------------------------------------------------
    |
    | Generic soft-deleted email collisions remain AMBIGUOUS.
    | Only entries listed here may authorize restore + MAP_EXISTING.
    |
    | U5-A APPROVED: WP 5 → restore Laravel user 3 → map existing.
    |
    */
    'approved_user_resolutions' => [
        [
            'legacy_id' => '5',
            'action' => 'RESTORE_AND_MAP_EXISTING',
            'local_user_id' => 3,
            'requires_soft_deleted' => true,
            'approval' => 'USER_5_RESTORE_AND_MAP_EXISTING_HIGH_CONFIDENCE',
            'never_copy_password' => true,
            'never_overwrite_profile' => true,
            'mapped_to_existing' => true,
            'created_by_migration' => false,
            'note' => 'U5-A: restore soft-deleted Laravel user 3; map WP 5 → local 3; revoke tokens first',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Historical product reviews (WP comments comment_type=review)
    |--------------------------------------------------------------------------
    |
    | All legacy reviews currently target skipped variable product 6912.
    | Reviews attach to the existing flagged Laravel kit (same business identity),
    | without importing/flattening the variable parent into the catalog.
    |
    | Guest strategy Option A: nullable product_reviews.user_id + guest_display_name.
    | Live POST /products/{slug}/reviews remains auth:sanctum only.
    |
    */
    'reviews' => [
        'source_product_legacy_id' => '6912',
        'destination_product_slug' => 'science-street-microscope-2',
        'destination_policy' => 'REVIEWS_MAP_TO_EXISTING_KIT_WITHOUT_IMPORTING_VARIABLE_PARENT',
        'guest_strategy' => 'NULLABLE_USER_WITH_DISPLAY_NAME',
        'entity_type' => 'product_review',
        'preserve_source_language' => true,
        'never_auto_translate' => true,
        'never_create_synthetic_users' => true,
        'skip_missing_rating' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Historical media M2 (R8.7+)
    |--------------------------------------------------------------------------
    |
    | WORDPRESS_MEDIA_SOURCE_ROOT — absolute path to extracted approved archive
    |   (contains files/{attachment_id}/{basename}). Required for dry-run/import.
    | WORDPRESS_MEDIA_HASH_MANIFEST — optional path to media-sha256.manifest.
    |   Defaults to sibling manifests/media-sha256.manifest near source root.
    | WORDPRESS_MEDIA_ALLOW_HTTP — MUST remain false in production. No network
    |   fallback for approved M2 files.
    |
    */
    'media' => [
        'source_root' => env('WORDPRESS_MEDIA_SOURCE_ROOT'),
        'hash_manifest' => env('WORDPRESS_MEDIA_HASH_MANIFEST'),
        'allow_http' => filter_var(env('WORDPRESS_MEDIA_ALLOW_HTTP', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
