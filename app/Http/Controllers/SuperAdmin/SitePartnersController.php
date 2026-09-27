<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\SuperAdmin\SitePartnerGateway;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ADR-211 — les Partenaires d'un site, gérés depuis le portail par l'API du
 * site : le même écran, les mêmes règles et le même audit qu'au site.
 */
class SitePartnersController extends SiteScreenController
{
    public function __invoke(Request $request, string $site, SitePartnerGateway $gateway, string $path = ''): SymfonyResponse|InertiaResponse
    {
        return $this->relay($request, $site, $gateway, $path);
    }

    /**
     * Le point d'entrée du portail : un site à choisir. Jamais une redirection
     * vers le premier site — un site injoignable y ramènerait sans fin.
     */
    public function overview(SitePartnerGateway $gateway): InertiaResponse
    {
        return Inertia::render('SuperAdmin/Partners/Index', [
            'sites' => collect(config('rivo.clinics', []))->map(fn (array $site) => [
                'code' => $site['code'],
                'name' => $site['name'],
                'url' => $gateway->base($site),
                'configured' => $gateway->configured($site),
            ])->values(),
        ]);
    }

    protected function contextKey(): string
    {
        return 'partnersContext';
    }

    protected function overviewRoute(): string
    {
        return 'super-admin.partners.index';
    }
}
