<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site (this deployment)
    |--------------------------------------------------------------------------
    |
    | Each operational deployment (one Laravel install per site) runs against
    | its own database and identifies itself through these values. This is
    | configuration, not a hardcoded list — a new site only needs a new .env,
    | never a code change.
    |
    */

    'site' => [
        'code' => env('RIVO_SITE_CODE'),
        'name' => env('RIVO_SITE_NAME'),
        'url' => env('RIVO_SITE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inter-site
    |--------------------------------------------------------------------------
    |
    | REST-only, never a direct DB connection (ADR-001/ADR-003). With only two
    | sites, "peer" is unambiguous. Now that a third site exists, whether each
    | site has exactly one peer or must reach several is not yet decided —
    | this holds a single URL until that is settled.
    |
    */

    'peer_site_url' => env('RIVO_PEER_SITE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Central services
    |--------------------------------------------------------------------------
    */

    'admin_url' => env('RIVO_ADMIN_URL'),
    'public_url' => env('RIVO_PUBLIC_URL'),

];
