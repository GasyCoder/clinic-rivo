<?php

namespace App\Services\SuperAdmin;

use App\Support\SurgeryReferenceData;
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

    /** @return array<int, array<string, mixed>> */
    public function modules(): array
    {
        return [
            [
                'code' => 'OVERVIEW', 'label' => 'Vue du site', 'icon' => 'growth',
                'description' => 'Activité et indicateurs du site.',
                'areas' => ['Activité du jour', 'Alertes', 'Services ouverts', 'État de l’API'],
            ],
            [
                'code' => 'RECEPTION', 'label' => 'Réception', 'icon' => 'card-view',
                'description' => 'Admissions patient et registre des visiteurs.',
                'areas' => ['Arrivées patient', 'Passages urgents', 'Visiteurs', 'Orientations'],
            ],
            [
                'code' => 'CASH', 'label' => 'Caisse', 'icon' => 'wallet',
                'description' => 'Unique point d’encaissement du site.',
                'areas' => ['Session de caisse', 'Factures', 'Paiements', 'Reçus et clôtures'],
            ],
            [
                'code' => 'PATIENTS', 'label' => 'Patients', 'icon' => 'users',
                'description' => 'Dossiers administratifs et passages.',
                'areas' => ['Patients', 'Épisodes', 'Doublons', 'Transferts autorisés'],
            ],
            [
                'code' => 'MEDICINE', 'label' => 'Médecine', 'icon' => 'user-list',
                'description' => 'Consultations, diagnostics et prescriptions.',
                'areas' => ['Consultations', 'Diagnostics', 'Prescriptions', 'Décisions médicales'],
            ],
            [
                'code' => 'CARE', 'label' => 'Soins', 'icon' => 'user-check',
                'description' => 'Soins infirmiers et constantes.',
                'areas' => ['Ordres de soins', 'Constantes', 'Soins en cours', 'Soins réalisés'],
            ],
            [
                'code' => 'SURGERY', 'label' => 'Chirurgie', 'icon' => 'grid-alt',
                'description' => 'Programmation, intervention et suivi opératoire.',
                'areas' => ['Programmation', 'Préopératoire', 'Interventions', 'Postopératoire'],
            ],
            [
                'code' => 'LABORATORY', 'label' => 'Laboratoire', 'icon' => 'activity',
                'description' => 'Demandes, prélèvements, analyses et résultats.',
                'areas' => ['Demandes', 'Prélèvements', 'Analyses', 'Résultats validés'],
            ],
            [
                'code' => 'PHARMACY', 'label' => 'Pharmacie', 'icon' => 'bag',
                'description' => 'Délivrance et gestion du stock de médicaments.',
                'areas' => ['Médicaments', 'Lots et péremptions', 'Entrées et sorties', 'Inventaires', 'Délivrances', 'Retours et transferts'],
                'notice' => 'Le stock de médicaments appartient à la Pharmacie. Aucun paiement ni encaissement n’est autorisé ici.',
            ],
            [
                'code' => 'HR', 'label' => 'Ressources humaines', 'icon' => 'briefcase',
                'description' => 'Employés, contrats, présence et organisation.',
                'areas' => ['Employés', 'Contrats', 'Présences et congés', 'Planning'],
            ],
            [
                'code' => 'LOGISTICS', 'label' => 'Logistique', 'icon' => 'package',
                'description' => 'Inventaire et suivi des équipements du site.',
                'areas' => ['Inventaire des équipements', 'Affectations et localisations', 'État et suivi', 'Maintenances', 'Mises hors service', 'Stock administratif'],
            ],
            [
                'code' => 'GUARDING', 'label' => 'Gardiennage', 'icon' => 'shield-check',
                'description' => 'Traçabilité des entrées et sorties du site.',
                'areas' => ['Nouvelle entrée', 'Présences en cours', 'Sorties', 'Observations et incidents', 'Historique'],
            ],
            [
                'code' => 'REPORTS', 'label' => 'Rapports', 'icon' => 'reports',
                'description' => 'Rapports autorisés du site.',
                'areas' => ['Activité', 'Finance', 'Stocks', 'Administration'],
            ],
            [
                'code' => 'CATALOG', 'label' => 'Référentiels & tarifs', 'icon' => 'setting-alt',
                'description' => 'Prestations et grilles tarifaires propres au site.',
                'areas' => ['Désignations', 'Tarifs sans mutuelle', 'Tarifs mutuelle', 'Historique tarifaire'],
                'notice' => 'Les montants restent propres au site. Le portail central les administre uniquement via l’API sécurisée du site sélectionné.',
            ],
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
                'areas' => ['Synthèse consolidée', 'Recettes par site', 'Revenus chirurgie par acte', 'Paiements et créances', 'Clôtures de caisse', 'Exports autorisés'],
                'surgical_revenue_rows' => array_map(fn (array $procedure): array => [
                    'code' => $procedure['code'],
                    'name' => $procedure['name'],
                    'planned' => null,
                    'actual' => null,
                    'difference' => null,
                    'unpaid_debt' => null,
                ], SurgeryReferenceData::procedures()),
            ],
            'HR' => [
                'title' => 'Ressources humaines',
                'description' => 'Gestion administrative des employés, distincte de la gestion des accès informatiques.',
                'icon' => 'briefcase',
                'areas' => ['Employés', 'Contrats', 'Présences et congés', 'Planning', 'Rapports RH'],
            ],
            'LOGISTICS' => [
                'title' => 'Logistique & équipements',
                'description' => 'Inventaire, affectation, localisation, état et maintenance des équipements.',
                'icon' => 'package',
                'areas' => ['Inventaire des équipements', 'Affectations et localisations', 'Suivi de l’état', 'Maintenances', 'Mises hors service', 'Stock administratif'],
            ],
            'GUARDING' => [
                'title' => 'Gardiennage',
                'description' => 'Enregistrement et suivi de toutes les entrées et sorties autorisées.',
                'icon' => 'shield-check',
                'areas' => ['Nouvelle entrée', 'Personnes présentes', 'Enregistrement des sorties', 'Observations et incidents', 'Historique et rapports'],
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
            'TARIFFS' => [
                'title' => 'Désignations & tarifs',
                'description' => 'Pilotage par site des prestations, tarifs sans mutuelle et tarifs mutuelle historisés.',
                'icon' => 'list-index',
                'areas' => ['Désignations par site', 'Tarifs sans mutuelle', 'Tarifs mutuelle', 'Historique et écarts', 'Publication contrôlée via API'],
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
