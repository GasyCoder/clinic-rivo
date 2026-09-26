<?php

namespace App\Services\SuperAdmin;

/**
 * ADR-187 — l'espace RH d'un site, vu du portail : `/administration` sur le
 * site, `/super-admin/sites/{code}/rh` sur le portail.
 */
class SiteHrGateway extends SiteScreenGateway
{
    public const API_PREFIX = 'super-admin/hr';

    public function apiPrefix(): string
    {
        return self::API_PREFIX;
    }

    public function siteRoot(): string
    {
        return '/administration';
    }

    protected function portalSegment(): string
    {
        return 'rh';
    }

    protected function screens(): array
    {
        return [
            'Administration/Index',
            'Administration/Employees/', 'Administration/Contracts/', 'Administration/Attendance/',
            'Administration/Leave/', 'Administration/Planning/', 'Administration/Reports/',
            'Administration/Settings/', 'Administration/Documents/', 'Administration/StaffBlockCredits/',
            'Administration/HrStructure/', 'Administration/ProfessionalEmails/', 'Administration/Internships/',
        ];
    }

    /** Seuls les écrans RH s'affichent depuis le portail. */
    public function isHrScreen(mixed $component): bool
    {
        return $this->isScreen($component);
    }
}
