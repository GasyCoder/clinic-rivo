<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /** @var array<int, string> */
    private const OBSOLETE_PERMISSIONS = [
        // Users are historical actors and are now deactivated, never deleted.
        'users.delete',
    ];

    /**
     * Clinical modules not yet implemented (laboratory, pharmacy, etc.)
     * still have no permission invented here — each seeds its own
     * when it is built, per ADR-008's action catalog.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'users.view' => 'Voir les utilisateurs',
        'users.create' => 'Créer un utilisateur',
        'users.update' => 'Modifier un utilisateur',
        'users.activate' => 'Réactiver un utilisateur',
        'users.deactivate' => 'Désactiver un utilisateur',
        'users.assign_super_admin' => 'Attribuer ou gérer le rôle Super Administrateur',
        'users.manage' => 'Gérer les comptes, rôles et permissions',

        'roles.view' => 'Voir les rôles',
        'roles.assign' => 'Attribuer un rôle',
        'permissions.view' => 'Voir les permissions',
        'permissions.assign' => 'Attribuer des permissions individuelles',

        // ADR-025 — portail central. Ces droits n'accordent aucun accès
        // direct aux bases locales : chaque lecture/écriture distante reste
        // soumise à l'API et aux permissions du site cible.
        'super_admin.portal.view' => 'Accéder au portail Super Administration',
        'sites.view' => 'Voir les sites et leurs modules',
        'reports.financial.view' => 'Voir les rapports financiers par site',
        'settings.view' => 'Voir les paramètres globaux',
        'settings.update' => 'Modifier les paramètres globaux',
        'audit.view' => 'Voir le journal d’audit',
        'api.view' => 'Voir l’état des intégrations API',

        // CDC officiel §17 + ADR-025. ADMINISTRATION est un rôle métier
        // RH/logistique/gardiennage, distinct de la gestion des comptes.
        'employees.view' => 'Voir les employés',
        'employees.create' => 'Créer un employé',
        'employees.update' => 'Modifier un employé',
        'employees.delete' => 'Archiver un employé',
        'employees.restore' => 'Restaurer un employé',
        'contracts.view' => 'Voir les contrats',
        'contracts.create' => 'Créer un contrat',
        'contracts.update' => 'Modifier un contrat',
        'contracts.archive' => 'Archiver un contrat',
        'attendance.view' => 'Voir les présences',
        'attendance.create' => 'Enregistrer une présence',
        'attendance.update' => 'Modifier une présence',
        'leave.view' => 'Voir les congés',
        'leave.create' => 'Créer une demande de congé',
        'leave.approve' => 'Approuver une demande de congé',
        'leave.cancel' => 'Annuler une demande de congé',
        'planning.view' => 'Voir les plannings',
        'planning.create' => 'Créer un planning',
        'planning.update' => 'Modifier un planning',
        'logistics.view' => 'Voir la logistique',
        'logistics.manage' => 'Gérer la logistique',
        'administrative_stock.view' => 'Voir le stock administratif',
        'administrative_stock.entry' => 'Enregistrer une entrée de stock administratif',
        'administrative_stock.exit' => 'Enregistrer une sortie de stock administratif',
        'administrative_stock.inventory' => 'Réaliser un inventaire administratif',
        'hr_reports.view' => 'Voir les rapports RH',
        'hr_reports.export' => 'Exporter les rapports RH',

        // ADR-024 — référentiel partagé localement par chaque site. Ces
        // permissions restent dynamiques, mais ne sont attribuées par
        // défaut qu'au SUPER_ADMIN dans RolePermissionSeeder.
        'catalog.items.view' => 'Voir le référentiel des produits et prestations',
        'catalog.items.create' => 'Créer un élément du référentiel',
        'catalog.items.update' => 'Modifier un élément du référentiel',
        'catalog.items.delete' => 'Archiver un élément du référentiel',
        'catalog.items.restore' => 'Restaurer un élément du référentiel',
        'catalog.tariffs.view' => 'Voir les tarifs et leur historique',
        'catalog.tariffs.create' => 'Créer un tarif',
        'catalog.tariffs.update' => 'Modifier un tarif',
        'catalog.tariffs.archive' => 'Suspendre un tarif',

        'reception.view' => 'Accéder à la réception',

        'visitors.view' => 'Voir le registre des visiteurs',
        'visitors.create' => 'Enregistrer l’entrée d’un visiteur',
        'visitors.update' => 'Corriger une visite',
        'visitors.close' => 'Enregistrer la sortie d’un visiteur',

        'patients.view' => 'Voir les patients',
        'patients.create' => 'Créer un patient',
        'patients.update' => 'Modifier un patient',
        'patients.delete' => 'Supprimer un patient',
        'patients.restore' => 'Restaurer un patient',
        'patients.view_deleted' => 'Voir les patients supprimés',
        'patients.force_delete' => 'Supprimer définitivement un patient',

        // module.resource.action (AI_CONTEXT.md) plutôt que patients.* :
        // ces deux permissions doivent pouvoir être restreintes séparément
        // du reste du dossier patient administratif (confidentialité des
        // informations médicales, CDCF client §34.1 règle 9).
        'patients.medical_history.view' => 'Voir les antécédents et allergies',
        'patients.medical_history.manage' => 'Gérer les antécédents et allergies',

        // CDC §12 liste aussi episodes.transfer — non seedée ici : le
        // transfert inter-sites (§19, Phase 7) n'a aucune implémentation
        // dans ce module, à ajouter quand ce flux sera réellement construit.
        'episodes.view' => 'Voir les épisodes',
        'episodes.create' => 'Créer un épisode',
        'episodes.update' => 'Modifier un épisode (orientation, ...)',
        'episodes.cancel' => 'Annuler un épisode',

        // CDC §15 / §34.2 — seule Réception / Caisse encaisse. Les
        // L'annulation contrôlée d'un paiement reste dans la session de
        // caisse ouverte qui l'a reçu. Remboursements, dettes et remises
        // restent absents tant que leurs validations distinctes ne sont pas
        // définies et implémentées.
        'billing.view' => 'Voir les factures et soldes',
        'billing.create' => 'Créer une facture',
        'billing.validate' => 'Valider une facture',
        'payments.view' => 'Voir les paiements',
        'payments.create' => 'Enregistrer un paiement',
        'payments.cancel' => 'Annuler un paiement',
        'cash.view' => 'Voir la caisse',
        'cash.open' => 'Ouvrir la caisse',
        'cash.close' => 'Clôturer la caisse',
        'receipts.view' => 'Voir les reçus',
        'receipts.print' => 'Imprimer les reçus',

        // CDC GitHub §15. medical_record.view, laboratory_orders.create,
        // hospitalization.request, surgery.request, transfer.request et
        // medical_discharge.create sont aussi listées là-bas mais non
        // seedées ici : aucune de ces capacités n'est implémentée tant que
        // Laboratoire/Hospitalisation/Chirurgie/Transfert/Sortie médicale
        // (§34) n'existent pas.
        'consultations.view' => 'Voir les consultations',
        'consultations.create' => 'Créer une consultation',
        'consultations.update' => 'Modifier une consultation',
        'consultations.delete' => 'Supprimer une consultation',
        'consultations.restore' => 'Restaurer une consultation',

        'diagnoses.view' => 'Voir les diagnostics',
        'diagnoses.create' => 'Créer un diagnostic',
        'diagnoses.update' => 'Modifier un diagnostic',

        'prescriptions.view' => 'Voir les prescriptions',
        'prescriptions.create' => 'Créer une prescription',
        'prescriptions.update' => 'Modifier une prescription',
        'prescriptions.cancel' => 'Annuler une prescription',

        // CDC §15 "Soins" — seedées ici en avance du module Soins/Vitals
        // (pas encore construit) car explicitement demandées pour le rôle
        // NURSE (ADR-006 amendé 2026-08-19), contrairement aux autres
        // permissions volontairement omises ci-dessus : le catalogue est
        // entièrement défini par le CDC, rien n'est inventé.
        'care.view' => 'Voir les soins',
        'care.create' => 'Créer un soin',
        'care.update' => 'Modifier un soin',
        'care.complete' => 'Marquer un soin comme réalisé',
        'vitals.view' => 'Voir les constantes',
        'vitals.create' => 'Enregistrer des constantes',
        'vitals.update' => 'Modifier des constantes',
        'medical_orders.view' => 'Voir les ordres médicaux',

        // CDC §16 "Chirurgie" — catalogue anesthésie, normalement rattaché
        // à SURGERY mais explicitement demandé aussi pour NURSE (ADR-006
        // amendé 2026-08-19) : une seule définition ici, référencée par les
        // deux rôles dans RolePermissionSeeder.
        'anesthesia.view' => 'Voir les dossiers d\'anesthésie',
        'anesthesia.create' => 'Créer un dossier d\'anesthésie',
        'anesthesia.update' => 'Modifier un dossier d\'anesthésie',
        'anesthesia.validate' => 'Valider un dossier d\'anesthésie',

        // Maternité : aucun catalogue de permissions n'existe dans le CDC
        // (aucune section dédiée, seulement la mention du profil
        // "sage-femme") — non inventé ici, à définir avec l'équipe.

        // CDC GitHub §15/16 — transcrit tel quel. Contrairement à
        // consultations (delete/restore) et prescriptions (cancel), ce
        // module n'a AUCUNE permission delete/restore/force_delete/cancel
        // listée pour surgery.*, alors que §11 exige explicitement le
        // refus du force_delete sur un "acte chirurgical validé". Conflit
        // documentaire signalé ici plutôt que résolu silencieusement :
        // aucune permission de suppression/annulation n'est inventée pour
        // combler ce vide tant que le CDC ou DECISIONS.md ne le précise pas.
        'surgery.view' => 'Voir les dossiers de chirurgie',
        'surgery.create' => 'Créer une demande de chirurgie',
        'surgery.update' => 'Modifier une demande de chirurgie',
        'surgery.schedule' => 'Programmer une intervention',
        'surgery.preoperative.view' => 'Voir le bilan préopératoire',
        'surgery.preoperative.validate' => 'Valider le bilan préopératoire',
        'surgery.intervention.create' => 'Créer une intervention',
        'surgery.intervention.update' => 'Modifier une intervention',
        'surgery.report.create' => 'Créer un compte rendu opératoire',
        'surgery.report.update' => 'Modifier un compte rendu opératoire',
        'surgery.report.validate' => 'Valider un compte rendu opératoire',
        'surgery.complications.create' => 'Enregistrer une complication',
        'surgery.discharge.create' => 'Enregistrer une sortie de chirurgie',
        'surgery.preparation.update' => 'Mettre à jour la préparation du bloc',
        'surgery.consumables.create' => 'Enregistrer un consommable utilisé',
        'surgery.care.create' => 'Enregistrer un soin peropératoire',
        'surgery.postoperative_care.create' => 'Enregistrer un soin postopératoire',
    ];

    public function run(): void
    {
        Permission::query()->whereIn('name', self::OBSOLETE_PERMISSIONS)->delete();

        foreach (self::PERMISSIONS as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]);
        }
    }
}
