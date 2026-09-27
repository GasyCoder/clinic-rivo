<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\SuperAdmin\SitePharmacyGateway;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ADR-189 — la Pharmacie d'un site, vue et administrée depuis le portail.
 *
 * Tous ses écrans se consultent ; l'administratif (médicaments, prix de vente,
 * familles, fournisseurs, commandes, factures) se gère ; les actes physiques
 * restent au site, qui les refuse au portail (`rivo.site-only`, ADR-098).
 */
class SitePharmacyController extends SiteScreenController
{
    public function __invoke(Request $request, string $site, SitePharmacyGateway $gateway, string $path = ''): SymfonyResponse|InertiaResponse
    {
        return $this->relay($request, $site, $gateway, $path);
    }

    protected function contextKey(): string
    {
        return 'pharmacyContext';
    }

    protected function overviewRoute(): string
    {
        return 'super-admin.stock.index';
    }
}
