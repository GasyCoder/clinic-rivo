<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    |
    | Shown across every deployment (clinic sites, the Super Admin portal,
    | the public entry point). Kept out of env by default since it does not
    | vary per deployment — override only if the organisation name changes.
    |
    */

    'brand' => env('RIVO_BRAND', 'Clinique Saint Georges'),

    /*
    |--------------------------------------------------------------------------
    | Current deployment identity
    |--------------------------------------------------------------------------
    |
    | ADR-002: one codebase, several independent deployments. Every request
    | must resolve which deployment it is running as purely from this config
    | (env-driven) — never by inspecting the request hostname, which breaks
    | under local dev, reverse proxies, or any environment where the host
    | header doesn't match production. `type` decides which routes exist.
    |
    | type: 'clinic' | 'admin' | 'public' — unset or unrecognised falls back
    | to 'clinic', the most tested path, so a misconfigured local checkout
    | never accidentally boots as something else.
    |
    */

    'site' => [
        'code' => env('RIVO_SITE_CODE'),
        'name' => env('RIVO_SITE_NAME'),
        'type' => env('RIVO_SITE_TYPE', 'clinic'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-deployment links
    |--------------------------------------------------------------------------
    |
    | Links only. No shared DB connection and no SQL crosses these — every
    | inter-site exchange happens over REST API (ADR-003), not built yet.
    |
    */

    'peer_site_url' => env('RIVO_PEER_SITE_URL'),
    'admin_url' => env('RIVO_ADMIN_URL', 'https://admin.rivo.mg'),
    'public_url' => env('RIVO_PUBLIC_URL', 'https://cliniquesaintgeorges.mg'),

    /*
    |--------------------------------------------------------------------------
    | Clinic directory
    |--------------------------------------------------------------------------
    |
    | Consulted only by the public "choose your site" entry point (site.type
    | = public) to build its redirect links. A clinic or admin deployment
    | never reads this list — it only knows its own identity above.
    |
    */

    'clinics' => [
        [
            'code' => 'M',
            'name' => 'Mampikony',
            'url' => env('RIVO_SITE_MAMPIKONY_URL', 'https://clinique-m.rivo.mg'),
        ],
        [
            'code' => 'A',
            'name' => 'Ambondromamy',
            'url' => env('RIVO_SITE_AMBONDROMAMY_URL', 'https://clinique-a.rivo.mg'),
        ],
        [
            'code' => 'B',
            'name' => 'Boriziny',
            'url' => env('RIVO_SITE_BORIZINY_URL', 'https://clinique-b.rivo.mg'),
        ],
    ],

];
