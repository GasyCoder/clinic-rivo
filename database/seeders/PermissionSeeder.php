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
     * Clinical modules not yet implemented (notably laboratory)
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
        'users.force_delete' => 'Supprimer définitivement un compte jamais utilisé (ADR-062)',

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
        'trash.view' => 'Voir la corbeille multi-sites',
        'trash.restore' => 'Restaurer un élément depuis la corbeille multi-sites',

        // CDC officiel §17, affiné par la décision projet qui sépare RH,
        // Logistique, Support et Maintenance en responsabilités autonomes.
        'employees.view' => 'Voir les employés',
        'employees.create' => 'Créer un employé',
        'employees.update' => 'Modifier un employé',
        'employees.delete' => 'Archiver un employé',
        'employees.restore' => 'Restaurer un employé',
        'employees.import' => 'Importer les employés',
        'employees.export' => 'Exporter les employés',
        'employees.print' => 'Imprimer une fiche employé',
        // Vue volontairement minimale du dossier RH pour relier un membre du
        // personnel à son dossier patient, sans exposer contrats ou données RH.
        'employees.patient_lookup' => 'Rechercher un employé pour son dossier patient',
        'staff_block_credits.view' => 'Voir le crédit forfaitaire Bloc et son historique',
        'staff_block_credits.allocate' => 'Allouer manuellement un crédit forfaitaire Bloc',
        'patient_staff_links.view' => 'Voir le lien patient-personnel',
        'patient_staff_links.create' => 'Relier un patient à un employé',
        'patient_staff_links.end' => 'Mettre fin à un lien patient-personnel',

        'address_entries.view' => 'Voir le référentiel des adresses',
        'address_entries.create' => 'Ajouter une adresse au référentiel',
        'address_entries.update' => 'Modifier une adresse du référentiel',
        'address_entries.archive' => 'Archiver une adresse du référentiel',
        'address_entries.restore' => 'Restaurer une adresse du référentiel',
        'address_entries.import' => 'Importer des adresses dans le référentiel',
        'address_entries.export' => 'Exporter le référentiel des adresses',

        'mutual_organizations.view' => 'Voir les organismes de mutuelle',
        'mutual_organizations.create' => 'Créer un organisme de mutuelle',
        'mutual_organizations.update' => 'Modifier un organisme de mutuelle',
        'mutual_organizations.archive' => 'Archiver un organisme de mutuelle',
        'mutual_organizations.restore' => 'Restaurer un organisme de mutuelle',
        'mutual_organizations.import' => 'Importer les organismes et leurs taux de couverture',
        'mutual_organizations.export' => 'Exporter les organismes et leurs taux de couverture',

        // Partenaire commercial/institutionnel (ISPSG, TsaraShop…), distinct
        // de la Mutuelle : couverture scopée à des prestations précises
        // (chambre, lit), jamais un taux sur toute la grille tarifaire.
        // Référentiel minimal pour l'instant — pas de gestion dédiée (voir
        // EpisodeFinancialMode::Partner).
        'partner_organizations.view' => 'Voir les organismes partenaires',

        'patient_coverages.view' => 'Voir la couverture administrative du patient',
        'patient_coverages.create' => 'Enregistrer une couverture mutuelle',
        'patient_coverages.update' => 'Modifier une couverture mutuelle',
        'patient_coverages.end' => 'Mettre fin à une couverture mutuelle',
        'patient_coverage_documents.view' => 'Voir les justificatifs privés de couverture',
        'patient_coverage_documents.create' => 'Ajouter un justificatif privé de couverture',
        'patient_coverage_documents.archive' => 'Archiver un justificatif privé de couverture',
        'contracts.view' => 'Voir les contrats',
        'contracts.create' => 'Créer un contrat',
        'contracts.update' => 'Modifier un contrat',
        'contracts.archive' => 'Archiver un contrat',
        'contracts.restore' => 'Restaurer un contrat archivé',
        'contracts.export' => 'Exporter les contrats',
        'contracts.print' => 'Imprimer un contrat',
        // Canevas de documents administratifs généralisés (contrat, congé,
        // attestation, certificat, lettre, décision...), composés sur le
        // portail Super Admin et poussés site par site (ADR-070). L'ancien
        // upload local de modèle Word/PDF (ADR-069) est retiré par ADR-071 :
        // un contrat fusionné passe désormais exclusivement par ce canevas.
        'document_templates.view' => 'Voir les canevas de documents',
        'document_templates.create' => 'Créer un canevas de document',
        'document_templates.update' => 'Modifier un canevas de document',
        'document_templates.archive' => 'Archiver un canevas de document',
        'document_templates.restore' => 'Restaurer un canevas de document',
        'document_templates.duplicate' => 'Dupliquer un canevas de document',
        'generated_documents.view' => 'Voir les documents générés',
        'generated_documents.create' => 'Générer un document administratif',
        'generated_documents.print' => 'Imprimer un document généré',
        'attendance.view' => 'Voir les présences',
        'attendance.create' => 'Enregistrer une présence',
        'attendance.update' => 'Modifier une présence',
        'attendance.export' => 'Exporter les présences',
        'attendance.print' => 'Imprimer les présences',
        'leave.view' => 'Voir les congés',
        'leave.create' => 'Créer une demande de congé',
        'leave.approve' => 'Approuver une demande de congé',
        'leave.reject' => 'Refuser une demande de congé',
        'leave.cancel' => 'Annuler une demande de congé',
        'leave.print' => 'Imprimer une demande de congé',
        'planning.view' => 'Voir les plannings',
        'planning.create' => 'Créer un planning',
        'planning.update' => 'Modifier un planning',
        'planning.export' => 'Exporter les plannings',
        'planning.print' => 'Imprimer les plannings',
        'hr_settings.view' => 'Voir les paramètres RH',
        'hr_settings.create' => 'Créer une valeur de paramétrage RH',
        'hr_settings.update' => 'Modifier une valeur de paramétrage RH',
        'hr_settings.archive' => 'Archiver une valeur de paramétrage RH',
        'hr_settings.restore' => 'Restaurer une valeur de paramétrage RH',
        'hr_documents.view' => 'Voir les documents privés RH',
        'hr_documents.create' => 'Ajouter un document privé RH',
        'hr_documents.archive' => 'Archiver un document privé RH',
        'hr_documents.restore' => 'Restaurer un document privé RH',
        'logistics.view' => 'Voir la logistique',
        'logistics.manage' => 'Gérer la logistique',
        'administrative_stock.view' => 'Voir le stock administratif',
        'administrative_stock.entry' => 'Enregistrer une entrée de stock administratif',
        'administrative_stock.exit' => 'Enregistrer une sortie de stock administratif',
        'administrative_stock.inventory' => 'Réaliser un inventaire administratif',
        'equipment.view' => 'Voir les équipements',
        'equipment.create' => 'Enregistrer un équipement',
        'equipment.update' => 'Modifier un équipement',
        'equipment.delete' => 'Archiver un équipement',
        'equipment.restore' => 'Restaurer un équipement',
        'equipment.assign' => 'Affecter ou déplacer un équipement',
        'equipment.inventory' => 'Réaliser l’inventaire des équipements',
        'equipment.maintenance.manage' => 'Suivre la maintenance des équipements',
        'equipment.decommission' => 'Mettre un équipement hors service',
        'guarding.view' => 'Accéder au poste de gardiennage',
        'guarding.entries.view' => 'Voir le journal des entrées et sorties',
        'guarding.entries.create' => 'Enregistrer une entrée',
        'guarding.entries.update' => 'Corriger une entrée ou une observation',
        'guarding.entries.close' => 'Enregistrer une sortie',
        'guarding.reports.view' => 'Voir les rapports de gardiennage',
        'guarding.reports.export' => 'Exporter les rapports de gardiennage',
        'hr_reports.view' => 'Voir les rapports RH',
        'hr_reports.export' => 'Exporter les rapports RH',
        'hr_reports.print' => 'Imprimer les rapports RH',

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
        'catalog.tariffs.import' => 'Importer les tarifs Standard et Mutuelle',
        'catalog.tariffs.export' => 'Exporter les tarifs Standard et Mutuelle',

        'reception.view' => 'Accéder à la réception',

        'visitors.view' => 'Voir le registre des visiteurs',
        'visitors.create' => 'Enregistrer l’entrée d’un visiteur',
        'visitors.update' => 'Corriger une visite',
        'visitors.close' => 'Enregistrer la sortie d’un visiteur',

        // CDC §13 + ADR-024. Le stock de médicaments reste dans Pharmacie.
        // Les prix et le référentiel sont gérés séparément par le Super Admin.
        'pharmacy.view' => 'Accéder à la pharmacie',
        'pharmacy.dispense' => 'Délivrer les médicaments autorisés',
        'pharmacy.dispense.prepare_invoice' => 'Préparer la facture d’une demande de dispensation',
        'pharmacy.dispense.print' => 'Voir et imprimer le ticket d’une demande de dispensation',
        'pharmacy.counter_sales.create' => 'Créer une vente directe au comptoir sans encaissement',
        'pharmacy.return' => 'Enregistrer un retour de pharmacie',
        'pharmacy.reports.view' => 'Voir les rapports de pharmacie',
        'pharmacy.reports.export' => 'Exporter les rapports de pharmacie',
        'medicines.view' => 'Voir le référentiel des médicaments',
        'medicines.create' => 'Créer un médicament dans le référentiel',
        'medicines.update' => 'Modifier le paramétrage d’un médicament',
        'medicines.delete' => 'Archiver un médicament',
        'medicines.restore' => 'Restaurer un médicament archivé',
        'medicines.import' => 'Importer en masse le référentiel des médicaments',
        'medicine_categories.view' => 'Voir les catégories thérapeutiques',
        'medicine_categories.create' => 'Créer une catégorie thérapeutique',
        'medicine_categories.update' => 'Modifier une catégorie thérapeutique',
        'medicine_categories.delete' => 'Archiver une catégorie thérapeutique',
        'medicine_categories.restore' => 'Restaurer une catégorie thérapeutique',
        'medicine_suppliers.view' => 'Voir les fournisseurs de médicaments',
        'medicine_suppliers.create' => 'Créer un fournisseur de médicaments',
        'medicine_suppliers.update' => 'Modifier un fournisseur de médicaments',
        'medicine_suppliers.delete' => 'Archiver un fournisseur de médicaments',
        'medicine_suppliers.restore' => 'Restaurer un fournisseur de médicaments',
        'stock.availability.view' => 'Consulter la disponibilité agrégée des médicaments',
        'stock.view' => 'Voir le stock de médicaments et consommables',
        'stock.entry' => 'Enregistrer une entrée en stock pharmacie',
        'stock.exit' => 'Enregistrer une sortie de stock pharmacie',
        'stock.adjust' => 'Ajuster le stock pharmacie',
        'stock.inventory' => 'Réaliser un inventaire du stock pharmacie',
        'stock.validate' => 'Valider un mouvement de stock pharmacie',
        'stock.transfer' => 'Transférer un stock pharmacie',
        'stock.approve' => 'Approuver un transfert de stock pharmacie',
        'stock.import' => 'Importer le stock pharmacie',
        'stock.export' => 'Exporter le stock pharmacie',
        'stock.lots.view' => 'Voir les lots de médicaments',
        'stock.lots.create' => 'Créer un lot de médicaments',
        'stock.lots.update' => 'Modifier un lot de médicaments',
        'stock.expiration.view' => 'Voir les péremptions',
        'stock.alerts.view' => 'Voir les alertes automatiques de stock',
        'stock.cost.view' => 'Voir les prix d’achat du stock',
        'stock.cost.record' => 'Enregistrer les prix d’achat du stock',

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
        'episodes.update' => 'Modifier un épisode',
        'episodes.mark_emergency' => 'Classer un épisode en urgence',
        'episodes.cancel' => 'Annuler un épisode',

        // CDC §15 / §34.2 — seule Réception / Caisse encaisse. Les
        // L'annulation contrôlée d'un paiement reste dans la session de
        // caisse ouverte qui l'a reçu. Remboursements, dettes et remises
        // restent absents tant que leurs validations distinctes ne sont pas
        // définies et implémentées.
        'billing.view' => 'Voir les factures et soldes',
        'billing.create' => 'Créer une facture',
        'billing.validate' => 'Valider une facture',
        'billing.print' => 'Voir et imprimer une facture',
        'payments.view' => 'Voir les paiements',
        'payments.create' => 'Enregistrer un paiement',
        'payments.cancel' => 'Annuler un paiement',
        'cash.view' => 'Voir la caisse',
        'cash.open' => 'Ouvrir la caisse',
        'cash.close' => 'Clôturer la caisse',
        'cash_registers.view' => 'Voir les caisses nommées du site',
        'cash_registers.create' => 'Créer une caisse nommée',
        'cash_registers.update' => 'Renommer une caisse nommée',
        'cash_registers.activate' => 'Réactiver une caisse nommée désactivée',
        'cash_registers.deactivate' => 'Désactiver une caisse nommée sans l’archiver',
        'cash_registers.archive' => 'Archiver une caisse nommée',
        'cash_registers.restore' => 'Restaurer une caisse nommée',
        'cash_registers.lock' => 'Verrouiller à distance une session de caisse ouverte',
        'cash_registers.unlock' => 'Déverrouiller à distance une session de caisse',
        'cash_registers.close' => 'Clôturer à distance une session avec comptage et motif',
        'cash_registers.export' => 'Exporter en Excel les mouvements et l’historique d’une caisse',
        'payment_methods.view' => 'Voir les modes de paiement acceptés par la caisse',
        'payment_methods.create' => 'Créer un mode de paiement',
        'payment_methods.update' => 'Modifier le libellé et le comportement de caisse d’un mode de paiement',
        'payment_methods.activate' => 'Réactiver un mode de paiement désactivé',
        'payment_methods.deactivate' => 'Désactiver un mode de paiement sans le supprimer',
        'receipts.view' => 'Voir les reçus',
        'receipts.print' => 'Imprimer les reçus',

        // CDC GitHub §15 / ADR-035. Les demandes Laboratoire,
        // Hospitalisation et Transfert restent absentes tant que leurs
        // workflows spécialisés ne sont pas réellement construits. Chirurgie
        // possède désormais son workflow séparé décrit par ADR-048.
        'medical_record.view' => 'Voir le dossier médical du passage',
        'consultations.view' => 'Voir les consultations',
        'consultations.create' => 'Créer une consultation',
        'consultations.update' => 'Modifier une consultation',
        'consultations.delete' => 'Supprimer une consultation',
        'consultations.restore' => 'Restaurer une consultation',

        'diagnoses.view' => 'Voir les diagnostics',
        'diagnoses.create' => 'Créer un diagnostic',
        'diagnoses.update' => 'Modifier un diagnostic',
        'diagnostic_catalog.view' => 'Voir le référentiel central des diagnostics',
        'diagnostic_catalog.manage' => 'Créer, modifier, activer et désactiver les diagnostics du référentiel',
        'analysis_catalog.view' => 'Voir le catalogue structuré des analyses',
        'analysis_catalog.create' => 'Créer une définition d’analyse',
        'analysis_catalog.update' => 'Modifier une définition d’analyse et ses références',
        'analysis_catalog.activate' => 'Activer une définition d’analyse',
        'analysis_catalog.deactivate' => 'Désactiver une définition d’analyse',
        'analysis_catalog.import' => 'Importer le catalogue des analyses',
        'analysis_catalog.export' => 'Exporter le catalogue des analyses',

        'prescriptions.view' => 'Voir les prescriptions',
        'prescriptions.create' => 'Créer une prescription',
        'prescriptions.update' => 'Modifier une prescription',
        'prescriptions.cancel' => 'Annuler une prescription',
        'medical_discharge.create' => 'Prononcer une sortie médicale',

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

        // Phase B — ordre de soins Médecine → Soins (CDC §15). Distincte de
        // medical_orders.view (générique, non consommée par un écran réel) :
        // celle-ci gouverne précisément la création/consultation d'un
        // CareOrder.
        'care_orders.create' => 'Demander un ordre de soins depuis une consultation',
        'care_orders.view' => 'Voir les ordres de soins',

        // Paraclinique et orientations depuis Médecine. surgery.request est
        // volontairement distincte de surgery.create (jamais accordée à
        // MEDICINE) : demander une intervention n'est pas piloter le
        // dossier chirurgical.
        'laboratory_orders.create' => 'Demander des analyses depuis une consultation',
        'laboratory_orders.view' => 'Voir les demandes d’analyses',
        'laboratory_results.view' => 'Voir les résultats d’analyses',
        'laboratory_results.create' => 'Saisir un résultat d’analyse',

        // ECG / échographie : aucun workspace dédié n'existe encore, donc
        // demande et compte rendu restent tous deux portés par Médecine.
        'imaging_orders.create' => 'Demander un examen d’imagerie depuis une consultation',
        'imaging_orders.view' => 'Voir les demandes d’imagerie',
        'imaging_results.create' => 'Saisir un compte rendu d’imagerie',

        'surgery.request' => 'Demander une intervention chirurgicale depuis Médecine',
        'hospitalization.request' => 'Demander une hospitalisation depuis Médecine',
        'maternity.request' => 'Demander une orientation Maternité depuis Médecine',
        'maternity.view' => 'Voir la file et les dossiers Maternité',
        'maternity.create' => 'Ouvrir un dossier Maternité',
        'maternity.update' => 'Mettre à jour un dossier Maternité',
        'maternity.complete' => 'Clôturer une prise en charge Maternité',
        'maternity.prenatal.manage' => 'Renseigner le suivi prénatal',
        'maternity.labor.manage' => 'Renseigner le travail et sa surveillance',
        'maternity.delivery.manage' => 'Renseigner l’accouchement ou demander une césarienne',
        'maternity.newborn.manage' => 'Renseigner les nouveau-nés et leurs soins',
        'maternity.procedures.manage' => 'Enregistrer les actes du catalogue Maternité',
        'transfer.request' => 'Demander un transfert/référence depuis Médecine',
        'pediatrics.request' => 'Demander une orientation Pédiatrie depuis Médecine',

        // CDC §16 "Chirurgie" — catalogue anesthésie. ADR-048 en fait un
        // espace autorisé séparément : ces permissions sont attribuées aux
        // comptes concernés, notamment au profil ANESTHETIST.
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
