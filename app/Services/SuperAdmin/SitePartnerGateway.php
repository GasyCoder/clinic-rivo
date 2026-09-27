<?php

namespace App\Services\SuperAdmin;

/**
 * ADR-211 — les Partenaires d'un site, vus du portail : `/partenaires` sur le
 * site, `/super-admin/sites/{code}/partenaires` sur le portail.
 */
class SitePartnerGateway extends SiteScreenGateway
{
    public function apiPrefix(): string
    {
        return 'super-admin/site-partners';
    }

    public function siteRoot(): string
    {
        return '/partenaires';
    }

    protected function portalSegment(): string
    {
        return 'partenaires';
    }

    protected function screens(): array
    {
        return ['Partners/'];
    }
}
