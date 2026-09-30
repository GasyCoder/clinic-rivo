<?php

namespace App\Services\SuperAdmin;

/**
 * ADR-229 — les dettes du personnel d'un site, dans Finance au portail :
 * `/super-admin/sites/{code}/finance/dettes`. Le site n'a pas d'écran de gestion :
 * ses écrans ne sont servis que par son API (routes/staff_debts.php). `/finance/dettes`
 * n'est qu'une racine de convention, qui ramène les adresses du site vers le portail.
 */
class SiteStaffDebtGateway extends SiteScreenGateway
{
    public function apiPrefix(): string
    {
        return 'super-admin/site-staff-debts';
    }

    public function siteRoot(): string
    {
        return '/finance/dettes';
    }

    protected function portalSegment(): string
    {
        return 'finance/dettes';
    }

    protected function screens(): array
    {
        return ['Finance/StaffDebts/'];
    }
}
