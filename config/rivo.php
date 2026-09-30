<?php

$localSiteApisEnabled = filter_var(
    env('RIVO_LOCAL_SITE_APIS', env('APP_ENV', 'production') === 'local'),
    FILTER_VALIDATE_BOOL,
);
$localSiteApiDefinitions = [
    'M' => [
        'code' => 'M',
        'name' => 'Mampikony',
        'host' => '127.0.0.1',
        'port' => (int) env('RIVO_LOCAL_API_MAMPIKONY_PORT', 8001),
        'token' => 'rivo-local-mampikony-api-2026',
    ],
    'A' => [
        'code' => 'A',
        'name' => 'Ambondromamy',
        'host' => '127.0.0.1',
        'port' => (int) env('RIVO_LOCAL_API_AMBONDROMAMY_PORT', 8002),
        'token' => 'rivo-local-ambondromamy-api-2026',
    ],
    'B' => [
        'code' => 'B',
        'name' => 'Boriziny',
        'host' => '127.0.0.1',
        'port' => (int) env('RIVO_LOCAL_API_BORIZINY_PORT', 8003),
        'token' => 'rivo-local-boriziny-api-2026',
    ],
];

$localApiUrl = static fn (string $code): string => sprintf(
    'http://%s:%d/api/v1',
    $localSiteApiDefinitions[$code]['host'],
    $localSiteApiDefinitions[$code]['port'],
);
$siteApiValue = static function (string $key, ?string $localValue = null) use ($localSiteApisEnabled): ?string {
    $configuredValue = trim((string) env($key, ''));

    if ($configuredValue !== '') {
        return $configuredValue;
    }

    return $localSiteApisEnabled ? $localValue : null;
};

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
    // La devise de l'établissement, sous la marque sur la page de connexion
    // (ADR-184). Un site la remplace depuis le portail ; RIVO_TAGLINE vide n'en
    // affiche aucune pour les sites qui n'ont rien réglé.
    'tagline' => env('RIVO_TAGLINE', 'Ny fahasalamana no loharanon-karena'),
    // ADR-184 — une application clinique n'a rien à montrer aux moteurs de
    // recherche : masquée par défaut (robots.txt, balise et en-tête « noindex »).
    // Réglable par site depuis le portail.
    'search_engines' => [
        'hidden' => (bool) env('RIVO_HIDE_FROM_SEARCH_ENGINES', true),
    ],
    'auth_cover_url' => env('RIVO_AUTH_COVER_URL') ?: '/images/brand/clinic-saint-georges-cover.jpg',

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
        'type' => strtolower(trim((string) env('RIVO_SITE_TYPE', 'clinic'))),
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
    | Printed document identity
    |--------------------------------------------------------------------------
    |
    | Legal and contact details shown on invoices and other official documents.
    | They vary by operational site and must be configured with verified values
    | at deployment time. No legal identifier is guessed by the application.
    |
    */

    'documents' => [
        'logo_url' => env('RIVO_DOCUMENT_LOGO_URL') ?: '/images/brand/clinic-saint-georges-logo.png',
        'nif' => env('RIVO_LEGAL_NIF'),
        'stat' => env('RIVO_LEGAL_STAT'),
        'address' => env('RIVO_LEGAL_ADDRESS'),
        'phone' => env('RIVO_LEGAL_PHONE'),
        'email' => env('RIVO_LEGAL_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local provisioning
    |--------------------------------------------------------------------------
    |
    | Optional UUID or email of the real, active account recorded as the
    | provisioning author when the explicit local clinical-service seeder is
    | run. This does not grant that account any catalog permission. The seeder
    | is never part of DatabaseSeeder and is refused outside local/testing.
    |
    */

    'seeders' => [
        'catalog_actor' => env('RIVO_CATALOG_SEED_ACTOR'),
        'development_users_password' => env('RIVO_DEVELOPMENT_USERS_PASSWORD', 'Rivo-Dev-2026!'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Clinic directory
    |--------------------------------------------------------------------------
    |
    | Consulted by the staff gateway for login links and by the central
    | Super Administration for its API directory. `api_url` is never a DB
    | connection and may remain null until the secured site API is deployed.
    |
    */

    'clinics' => [
        [
            'code' => 'M',
            'name' => 'Mampikony',
            'url' => env('RIVO_SITE_MAMPIKONY_URL', 'https://clinique-m.rivo.mg'),
            'api_url' => $siteApiValue('RIVO_API_MAMPIKONY_URL', $localApiUrl('M')),
            'api_token' => $siteApiValue('RIVO_API_MAMPIKONY_TOKEN', $localSiteApiDefinitions['M']['token']),
        ],
        [
            'code' => 'A',
            'name' => 'Ambondromamy',
            'url' => env('RIVO_SITE_AMBONDROMAMY_URL', 'https://clinique-a.rivo.mg'),
            'api_url' => $siteApiValue('RIVO_API_AMBONDROMAMY_URL', $localApiUrl('A')),
            'api_token' => $siteApiValue('RIVO_API_AMBONDROMAMY_TOKEN', $localSiteApiDefinitions['A']['token']),
        ],
        [
            'code' => 'B',
            'name' => 'Boriziny',
            'url' => env('RIVO_SITE_BORIZINY_URL', 'https://clinique-b.rivo.mg'),
            'api_url' => $siteApiValue('RIVO_API_BORIZINY_URL', $localApiUrl('B')),
            'api_token' => $siteApiValue('RIVO_API_BORIZINY_TOKEN', $localSiteApiDefinitions['B']['token']),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local distributed clinic APIs
    |--------------------------------------------------------------------------
    |
    | Local development keeps the production topology: one admin portal calls
    | three HTTP APIs backed by three independent SQLite databases. The tokens
    | below are fixed local-only credentials and are never used outside local.
    |
    */

    'local_site_apis' => [
        'enabled' => $localSiteApisEnabled,
        'database_directory' => storage_path('app/local-sites'),
        'sites' => array_values($localSiteApiDefinitions),
    ],

    /*
    |--------------------------------------------------------------------------
    | Secured site API
    |--------------------------------------------------------------------------
    |
    | Each clinic accepts one deployment secret for the fixed, read-mostly
    | Super Administration API scope. The central portal keeps one distinct
    | token per clinic. These values are secrets and must never be committed.
    |
    */

    /*
     * ADR-190 — adresses email professionnelles.
     *
     * Le domaine est celui de la clinique : le même sur les trois sites et le
     * portail. L'hébergeur (API cPanel) n'est lu que par le portail : son jeton
     * donne accès à tout l'hébergement, il ne se pose jamais sur un site clinique.
     */
    'professional_email' => [
        'domain' => env('RIVO_PROFESSIONAL_EMAIL_DOMAIN', ''),
        'hosting' => [
            // ex. https://kitty.o2switch.net:2083
            'url' => env('RIVO_MAIL_HOSTING_URL'),
            'user' => env('RIVO_MAIL_HOSTING_USER'),
            // Un jeton API de préférence ; à défaut (outil absent de l'offre o2switch),
            // le mot de passe du compte cPanel. Le jeton l'emporte s'il est renseigné.
            'token' => env('RIVO_MAIL_HOSTING_TOKEN'),
            'password' => env('RIVO_MAIL_HOSTING_PASSWORD'),
            // Taille de chaque boîte créée, en Mo (réglage technique, modifiable).
            'quota_mb' => (int) env('RIVO_MAIL_HOSTING_QUOTA_MB', 1024),
            'timeout' => (int) env('RIVO_MAIL_HOSTING_TIMEOUT', 15),
            // Mode mot de passe : minutes pendant lesquelles une session cPanel est
            // réutilisée (la connexion est l'étape lente). 0 = une session par opération.
            'session_minutes' => (int) env('RIVO_MAIL_HOSTING_SESSION_MINUTES', 10),
        ],
    ],

    /*
     * ADR-195 — la messagerie : la boîte pro de l'employé chez l'hébergeur, lue en
     * IMAP et envoyée en SMTP. Aucun secret ici : le mot de passe de la boîte est
     * saisi par son titulaire et ne vit que dans sa session. Sans hôte réglé, celui
     * de l'hébergement (RIVO_MAIL_HOSTING_URL) sert pour les deux.
     */
    'webmail' => [
        'imap' => [
            'host' => env('RIVO_WEBMAIL_IMAP_HOST') ?: parse_url((string) env('RIVO_MAIL_HOSTING_URL'), PHP_URL_HOST),
            'port' => (int) env('RIVO_WEBMAIL_IMAP_PORT', 993),
            // ssl (993), tls (STARTTLS, 143) ou none (serveur de test local seulement).
            'encryption' => env('RIVO_WEBMAIL_IMAP_ENCRYPTION', 'ssl'),
            'validate_cert' => (bool) env('RIVO_WEBMAIL_VALIDATE_CERT', true),
        ],
        'smtp' => [
            'host' => env('RIVO_WEBMAIL_SMTP_HOST') ?: parse_url((string) env('RIVO_MAIL_HOSTING_URL'), PHP_URL_HOST),
            // 465 : TLS dès la connexion — deux allers-retours de moins que 587 (STARTTLS),
            // et o2switch accepte les deux. `tls` = STARTTLS, `ssl` = TLS implicite.
            'port' => (int) env('RIVO_WEBMAIL_SMTP_PORT', 465),
            'encryption' => env('RIVO_WEBMAIL_SMTP_ENCRYPTION', 'ssl'),
        ],
        // Portail seulement : la boîte du Super Admin, ouverte sans saisie. Son mot de passe
        // vit dans le .env du portail, comme l'accès cPanel (ADR-190) — jamais en base.
        'portal' => [
            'address' => env('RIVO_WEBMAIL_PORTAL_ADDRESS'),
            'password' => env('RIVO_WEBMAIL_PORTAL_PASSWORD'),
            'name' => env('RIVO_WEBMAIL_PORTAL_NAME'),
        ],
        'timeout' => (int) env('RIVO_WEBMAIL_TIMEOUT', 20),
        // La connexion au serveur de messagerie gardée ouverte entre les clics : un petit
        // processus par boîte ouverte, qui se ferme seul après `idle_minutes` sans usage.
        // Seul le premier clic paie la connexion (chiffrement, identification). Sans
        // processus possible sur le serveur, RIVO se connecte à chaque clic, comme avant.
        'keep_alive' => [
            'enabled' => (bool) env('RIVO_WEBMAIL_KEEP_ALIVE', true),
            'idle_minutes' => (int) env('RIVO_WEBMAIL_KEEP_ALIVE_MINUTES', 10),
            // Entre deux clics, un NOOP toutes les N secondes : un routeur (NAT, box) oublie
            // une connexion muette sans prévenir, et elle serait rouverte au clic suivant
            // après tout le délai d'attente. 15 secondes au moins.
            'heartbeat_seconds' => (int) env('RIVO_WEBMAIL_KEEP_ALIVE_HEARTBEAT', 60),
            // Le PHP en ligne de commande qui lance ce processus (sous PHP-FPM, PHP_BINARY
            // désigne php-fpm) : déduit s'il est vide.
            'php' => env('RIVO_WEBMAIL_KEEP_ALIVE_PHP'),
            // Le dossier de ses prises (sockets Unix, 108 caractères au plus) : déduit s'il est vide.
            'path' => env('RIVO_WEBMAIL_KEEP_ALIVE_PATH'),
            // Le temps laissé au processus pour démarrer, en secondes.
            'start_timeout' => (float) env('RIVO_WEBMAIL_KEEP_ALIVE_START_TIMEOUT', 5),
        ],
        'per_page' => 25,
        // Pièces jointes d'un message envoyé : par fichier et au total, en Mo.
        'attachment_max_mb' => (int) env('RIVO_WEBMAIL_ATTACHMENT_MAX_MB', 10),
        'attachments_total_mb' => (int) env('RIVO_WEBMAIL_ATTACHMENTS_TOTAL_MB', 20),
    ],

    /*
     * ADR-202 — la première connexion d'un employé : il tape son adresse, RIVO le
     * salue et lui demande de choisir son mot de passe. Ouverte ce nombre de jours
     * après l'envoi de l'accès au RH ; le RH peut la rouvrir.
     */
    'account_activation' => [
        'days' => (int) env('RIVO_ACCOUNT_ACTIVATION_DAYS', 14),
    ],

    /*
     * ADR-222 — l'assistant d'utilisation du logiciel. Il se règle depuis le portail
     * (Paramètres › Assistant IA), site par site, et ces valeurs ne servent qu'en
     * secours quand rien n'y est réglé. La clé d'un fournisseur n'est jamais ici : en
     * secours, c'est la variable du SDK (OPENAI_API_KEY, ANTHROPIC_API_KEY…).
     */
    'assistant' => [
        'enabled' => (bool) env('RIVO_AI_ENABLED', false),
        'provider' => env('RIVO_AI_PROVIDER'),
        'model' => env('RIVO_AI_MODEL'),
        'max_output_tokens' => (int) env('RIVO_AI_MAX_OUTPUT_TOKENS', 800),
        'timeout' => (int) env('RIVO_AI_TIMEOUT', 30),
        'rate_limit_per_hour' => (int) env('RIVO_AI_RATE_LIMIT_PER_HOUR', 20),
    ],

    'site_api' => [
        'token' => env('RIVO_SITE_API_TOKEN'),
        'timeout' => (int) env('RIVO_SITE_API_TIMEOUT', 5),
        'retry_times' => (int) env('RIVO_SITE_API_RETRY_TIMES', 2),
    ],

];
