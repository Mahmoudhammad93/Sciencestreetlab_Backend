<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical competition slug aliases
    |--------------------------------------------------------------------------
    |
    | Frontend routes use English canonical slugs (e.g. microscope-100-challenge).
    | WordPress historical import created the live competition under a different
    | slug. Resolve any alias in the same group to the same Competition row.
    |
    */
    'slug_alias_groups' => [
        [
            'microscope-100-challenge',
            'thdy-al100-sor-bastkhdam-mykroskob-sharaa-alaalom',
        ],
    ],

];
