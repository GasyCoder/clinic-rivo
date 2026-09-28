<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\SuperAdmin\SiteLaboratoryGateway;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ADR-215 — le Laboratoire d'un site, consulté depuis le portail par l'API du
 * site : la file, les demandes, les résultats, l'historique et les rapports, et
 * la gestion des référentiels (prélèvements et tubes, microbiologie). Réceptionner,
 * prélever, saisir et valider restent au site.
 */
class SiteLaboratoryController extends SiteScreenController
{
    public function __invoke(Request $request, string $site, SiteLaboratoryGateway $gateway, string $path = ''): SymfonyResponse|InertiaResponse
    {
        return $this->relay($request, $site, $gateway, $path);
    }

    /**
     * Le point d'entrée du portail : un site à choisir. Jamais une redirection
     * vers le premier site — un site injoignable y ramènerait sans fin.
     */
    public function overview(SiteLaboratoryGateway $gateway): InertiaResponse
    {
        return Inertia::render('SuperAdmin/Laboratory/Index', [
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
        return 'laboratoryContext';
    }

    protected function overviewRoute(): string
    {
        return 'super-admin.laboratory.index';
    }
}
