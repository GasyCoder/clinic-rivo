<?php

namespace App\Services\SuperAdmin;

/**
 * ADR-189 — la Pharmacie d'un site, vue du portail : `/pharmacy` sur le site,
 * `/super-admin/sites/{code}/pharmacie` sur le portail.
 *
 * Le préfixe d'API n'est pas `super-admin/pharmacy`, déjà pris par le stock et
 * les fournisseurs du portail (ADR-042, ADR-098).
 */
class SitePharmacyGateway extends SiteScreenGateway
{
    public function apiPrefix(): string
    {
        return 'super-admin/site-pharmacy';
    }

    public function siteRoot(): string
    {
        return '/pharmacy';
    }

    protected function portalSegment(): string
    {
        return 'pharmacie';
    }

    protected function screens(): array
    {
        return ['Pharmacy/'];
    }

    /**
     * `/pharmacy` renvoie, sur le site, vers la vue d'ensemble — une page du
     * site, pas de la Pharmacie. Le portail arrive donc sur le stock.
     */
    public function landingPath(): string
    {
        return 'stock';
    }
}
