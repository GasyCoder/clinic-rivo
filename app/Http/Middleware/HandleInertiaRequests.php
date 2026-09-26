<?php

namespace App\Http\Middleware;

use App\Services\Settings\AppSettings;
use App\Services\Settings\SiteMaintenanceState;
use App\Services\SuperAdmin\PortalDirectory;
use App\Services\Webmail\WebmailAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $settings = app(AppSettings::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ? [
                        'code' => $user->role->code,
                        'name' => $user->role->name,
                    ] : null,
                    'professional_profile' => $user->professionalProfile ? [
                        'code' => $user->professionalProfile->code,
                        'name' => $user->professionalProfile->name,
                    ] : null,
                ] : null,
            ],
            'permissions' => $user ? $user->effectivePermissionNames()->values()->all() : [],
            'adminNavigation' => fn () => $user
                && config('rivo.site.type') === 'admin'
                && $user->can('super_admin.portal.view')
                    ? app(PortalDirectory::class)->navigation()
                    : [],
            // ADR-184 — nom, devise, icône, identité légale, écriture de l'Ariary et
            // tranches d'âge : les paramètres du site, à défaut sa configuration.
            'site' => [
                'brand' => $settings->brand(),
                'tagline' => $settings->tagline(),
                'code' => config('rivo.site.code'),
                'name' => config('rivo.site.name'),
                'type' => config('rivo.site.type'),
                'gatewayUrl' => config('rivo.gateway_url'),
                'publicUrl' => config('rivo.public_url'),
                // ADR-184 — l'image de fond et le modèle des pages d'authentification,
                // et le modèle de « Mon profil ».
                'authCoverUrl' => $settings->authBackgroundUrl(),
                'authTemplate' => $settings->authTemplate()->value,
                'profileTemplate' => $settings->profileTemplate()->value,
                'documents' => $settings->documents(),
                'iconUrl' => $settings->iconUrl(),
                'currency' => $settings->currency(),
                'ageBands' => $settings->ageBands(),
                // ADR-193 — la maintenance du site : bandeau d'avertissement avant son
                // début, bandeau pour le compte qui la traverse, avis sur la connexion.
                'maintenance' => app(SiteMaintenanceState::class)->sharedProp($user),
            ],
            // ADR-195 — la messagerie : proposée selon les permissions (`webmail.view`,
            // `webmail.open_any`) ; `connected` dit si une boîte est ouverte — celle du
            // portail l'est toujours, son mot de passe vivant dans son .env.
            'webmail' => fn () => [
                'available' => app(WebmailAccess::class)->canUse($user),
                'connected' => app(WebmailAccess::class)->password(app(WebmailAccess::class)->current($user)) !== null,
            ],
            // ADR-191 — taille du texte, densité, arrondis, animations, contraste :
            // ceux du site, ajustés par l'utilisateur. Appliqués sur <html> dès le rendu
            // serveur ; la page les réapplique quand l'utilisateur les change.
            'appearance' => $settings->appearance($user),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                // Optional tone for the status toast (success/warning/danger/
                // info). Defaults to success client-side when absent, so the
                // dozens of existing ->with('status', ...) calls need no
                // change — only a message that isn't a plain success sets it.
                'status_type' => fn () => $request->session()->get('status_type'),
                'duplicates' => fn () => $request->session()->get('duplicates'),
                // Rapport d'une action groupée : ce qui est passé, ce qui ne l'est pas, et pourquoi.
                'bulk_report' => fn () => $request->session()->get('bulk_report'),
                'print_ticket_url' => fn () => $request->session()->get('print_ticket_url'),
            ],
        ];
    }
}
