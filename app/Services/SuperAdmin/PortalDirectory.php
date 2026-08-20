<?php

namespace App\Services\SuperAdmin;

use Illuminate\Support\Collection;

/**
 * Static directory for the central portal shell. It deliberately performs no
 * remote call: site data will enter only through authenticated API clients.
 */
class PortalDirectory
{
    /** @return Collection<int, array<string, mixed>> */
    public function sites(): Collection
    {
        return collect(config('rivo.clinics', []))->map(fn (array $site) => [
            'code' => $site['code'],
            'name' => $site['name'],
            'integration_status' => filled($site['api_url'] ?? null) ? 'CONFIGURED' : 'PENDING',
            'modules' => $this->modules(),
        ])->values();
    }

    /** @return array<string, mixed> */
    public function site(string $code): array
    {
        $site = $this->sites()->firstWhere('code', mb_strtoupper($code));

        abort_unless($site, 404);

        return $site;
    }

    /** @return array<int, array{code: string, label: string, icon: string}> */
    public function modules(): array
    {
        return [
            ['code' => 'OVERVIEW', 'label' => 'Vue du site', 'icon' => 'growth'],
            ['code' => 'RECEPTION', 'label' => 'Réception', 'icon' => 'card-view'],
            ['code' => 'CASH', 'label' => 'Caisse', 'icon' => 'wallet'],
            ['code' => 'PATIENTS', 'label' => 'Patients', 'icon' => 'users'],
            ['code' => 'MEDICINE', 'label' => 'Médecine', 'icon' => 'user-list'],
            ['code' => 'CARE', 'label' => 'Soins', 'icon' => 'user-check'],
            ['code' => 'SURGERY', 'label' => 'Chirurgie', 'icon' => 'grid-alt'],
            ['code' => 'LABORATORY', 'label' => 'Laboratoire', 'icon' => 'activity'],
            ['code' => 'PHARMACY', 'label' => 'Pharmacie', 'icon' => 'bag'],
            ['code' => 'STOCK', 'label' => 'Stocks', 'icon' => 'package'],
            ['code' => 'ADMINISTRATION', 'label' => 'Administration', 'icon' => 'briefcase'],
            ['code' => 'REPORTS', 'label' => 'Rapports', 'icon' => 'reports'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function navigation(): array
    {
        return $this->sites()->map(fn (array $site) => [
            'code' => $site['code'],
            'name' => $site['name'],
            'integration_status' => $site['integration_status'],
            'modules' => collect($this->modules())->map(fn (array $module) => [
                ...$module,
                'link' => '/super-admin/sites/'.$site['code'].'?module='.$module['code'],
            ])->all(),
        ])->all();
    }

    /** @return array<string, mixed> */
    public function workspace(string $code): array
    {
        $workspaces = [
            'FINANCE' => [
                'title' => 'Rapports financiers par site',
                'description' => 'Recettes, paiements, soldes et clôtures, consolidés sans contourner la caisse unique de chaque site.',
                'icon' => 'wallet',
                'areas' => ['Synthèse consolidée', 'Recettes par site', 'Paiements et créances', 'Clôtures de caisse', 'Exports autorisés'],
            ],
            'ADMINISTRATION' => [
                'title' => 'Administration',
                'description' => 'Fonctions internes de la clinique, distinctes de la gestion des accès informatiques.',
                'icon' => 'briefcase',
                'areas' => ['Employés et RH', 'Contrats', 'Présences et congés', 'Planning', 'Logistique', 'Stock administratif', 'Gardiennage et visiteurs', 'Rapports RH'],
            ],
            'USERS' => [
                'title' => 'Gestion des utilisateurs',
                'description' => 'Comptes nominatifs, affectation par site, activation et désactivation auditée.',
                'icon' => 'users',
                'areas' => ['Utilisateurs Mampikony', 'Utilisateurs Ambondromamy', 'Utilisateurs Boriziny', 'Comptes Super Administration', 'Cycle d’accès'],
            ],
            'ROLES' => [
                'title' => 'Rôles & permissions',
                'description' => 'Droits dynamiques par métier, avec exceptions individuelles et DENY prioritaire.',
                'icon' => 'shield-check',
                'areas' => ['Rôles principaux', 'Permissions par module', 'Affectations par site', 'Exceptions individuelles', 'Historique des changements'],
            ],
            'SETTINGS' => [
                'title' => 'Paramètres',
                'description' => 'Identité de l’application et paramètres globaux diffusés de façon contrôlée.',
                'icon' => 'setting-alt',
                'areas' => ['Nom de l’application', 'Identité des sites', 'Numérotation', 'Référentiels globaux', 'Paramètres API'],
            ],
            'AUDIT' => [
                'title' => 'Audit & intégrations',
                'description' => 'Traçabilité des actions sensibles et état des communications avec chaque site.',
                'icon' => 'history',
                'areas' => ['Journal Super Admin', 'Audit par site', 'État des APIs', 'Échecs et reprises', 'Requêtes idempotentes'],
            ],
        ];

        abort_unless(array_key_exists($code, $workspaces), 404);

        return ['code' => $code, ...$workspaces[$code]];
    }
}
