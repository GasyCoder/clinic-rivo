<?php

namespace App\Services\SuperAdmin;

/**
 * ADR-215 — le Laboratoire d'un site, vu du portail : `/laboratory` sur le
 * site, `/super-admin/sites/{code}/laboratoire` sur le portail. Les écrans et
 * les règles restent ceux du site ; les gestes cliniques y sont refusés au
 * Super Admin (`rivo.site-only:laboratory`).
 */
class SiteLaboratoryGateway extends SiteScreenGateway
{
    public function apiPrefix(): string
    {
        return 'super-admin/site-laboratory';
    }

    public function siteRoot(): string
    {
        return '/laboratory';
    }

    protected function portalSegment(): string
    {
        return 'laboratoire';
    }

    protected function screens(): array
    {
        return ['Laboratory/'];
    }
}
