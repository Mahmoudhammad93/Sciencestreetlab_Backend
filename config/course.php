<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical course slug aliases
    |--------------------------------------------------------------------------
    |
    | Frontend / marketing routes use English canonical slugs (e.g.
    | microscope-course). Production WordPress import left the live course
    | under an Arabic slug. Resolve any alias in the same group to the same
    | Course row without mutating the stored slug.
    |
    */
    'slug_alias_groups' => [
        [
            'microscope-course',
            'كورس-الميكروسكوب',
        ],
    ],

];
