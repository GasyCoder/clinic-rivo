<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    |
    | Shown across every deployment of this codebase (clinic sites, the
    | Super Admin portal, the staff gateway). Kept out of env by default
    | since it does not vary per deployment — override only if the
    | organisation name changes.
    |
    | Does NOT cover cliniquesaintgeorges.mg, the public marketing site —
    | that domain is a separate concern, not a deployment of this codebase,
    | and nothing here renders anything for it.
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
    | type: 'clinic' | 'admin' | 'gateway' — unset or unrecognised falls back
    | to 'clinic', the most tested path, so a misconfigured local checkout
    | never accidentally boots as something else.
    |
    | 'gateway' is app.rivo.mg: the staff entry point that lets a user pick
    | which clinic to log into (Mampikony/Ambondromamy/Boriziny). It is a
    | deployment of THIS codebase, unlike cliniquesaintgeorges.mg (public
    | marketing site, untouched, out of scope here) — don't conflate the two.
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
    | public_url (cliniquesaintgeorges.mg) is kept here purely as a link
    | target for the rest of the app to reference if needed later — this
    | codebase never renders anything for that domain.
    |
    */

    'peer_site_url' => env('RIVO_PEER_SITE_URL'),
    'admin_url' => env('RIVO_ADMIN_URL', 'https://admin.rivo.mg'),
    'public_url' => env('RIVO_PUBLIC_URL', 'https://cliniquesaintgeorges.mg'),
    'gateway_url' => env('RIVO_GATEWAY_URL', 'https://app.rivo.mg'),

    /*
    |--------------------------------------------------------------------------
    | Clinic directory
    |--------------------------------------------------------------------------
    |
    | Consulted only by the staff gateway (site.type = gateway, app.rivo.mg)
    | to build its redirect links. A clinic or admin deployment never reads
    | this list — it only knows its own identity above.
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
