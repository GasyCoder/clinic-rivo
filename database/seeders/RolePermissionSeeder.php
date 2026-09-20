<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class RolePermissionSeeder extends Seeder
{
    /**
     * Exact permission names or "prefix." globs per role — explicit,
     * because the naive "grant every permission that exists" this used to
     * do (before Patient/Episode/Médecine permissions existed) would now
     * leak medical records and episodes to ADMINISTRATION, a role CDCF
     * §21's profile table scopes to "paramétrage autorisé" only.
     *
     * @var array<string, array<int, string>>
     */
    private const GRANTS = [
        'ADMINISTRATION' => [
            'employees.view', 'employees.create', 'employees.update',
            'employees.delete', 'employees.restore',
            'employees.import', 'employees.export', 'employees.print',
            'employees.patient_lookup',
            // ADR-133 — une liste de patients est une donnée personnelle.
            'patients.export',
            'staff_block_credits.view', 'staff_block_credits.allocate',
            // CDC §33.3 / §34.1 règle 6 — la « personne habilitée » qui
            // autorise la dérogation « dette validée ». Elle reçoit aussi
            // la file de règlement et le droit de prononcer la sortie,
            // sans quoi l'autorisation seule ne permettrait rien : aucun
            // rôle ne posséderait les deux droits et la dérogation serait
            // impossible par défaut. Elle n'encaisse toujours rien —
            // aucune permission payments.*/cash.* ici (ADR-012).
            'debts.view', 'debts.authorize', 'debts.record_escape',
            'episodes.settlement.view', 'episodes.administrative_exit',
            'patient_staff_links.view', 'patient_staff_links.create', 'patient_staff_links.end',
            'address_entries.view', 'address_entries.create', 'address_entries.update',
            'address_entries.archive',
            'address_entries.import', 'address_entries.export',
            'mutual_organizations.view', 'mutual_organizations.create',
            'mutual_organizations.update', 'mutual_organizations.archive',
            'mutual_organizations.import',
            'mutual_organizations.export',
            'patient_coverages.view', 'patient_coverages.update', 'patient_coverages.end',
            'patient_coverage_documents.view', 'patient_coverage_documents.archive',
            'contracts.view', 'contracts.create', 'contracts.update', 'contracts.archive',
            'contracts.restore', 'contracts.export', 'contracts.print',
            // Canevas: authored/pushed centrally by SUPER_ADMIN only (ADR-070,
            // same site.type==='admin' gate as below) — ADMINISTRATION gets
            // read-only access to the synced local copy, plus full control of
            // what it actually produces (generated_documents.*).
            'document_templates.view',
            'generated_documents.view', 'generated_documents.create', 'generated_documents.print',
            'attendance.view', 'attendance.create', 'attendance.update',
            'attendance.export', 'attendance.print',
            'leave.view', 'leave.create', 'leave.approve', 'leave.reject',
            'leave.cancel', 'leave.print',
            'planning.view', 'planning.create', 'planning.update',
            'planning.export', 'planning.print',
            'hr_settings.view', 'hr_settings.create', 'hr_settings.update',
            'hr_settings.archive', 'hr_settings.restore',
            'hr_documents.view', 'hr_documents.create',
            'hr_documents.archive', 'hr_documents.restore',
            'hr_reports.view', 'hr_reports.export', 'hr_reports.print',
            'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
            'cash_registers.activate', 'cash_registers.deactivate',
            'cash_registers.archive',
            'diagnostic_catalog.view', 'diagnostic_catalog.manage',
            'analysis_catalog.view', 'analysis_catalog.create', 'analysis_catalog.update',
            'analysis_catalog.activate', 'analysis_catalog.deactivate',
            'analysis_catalog.import', 'analysis_catalog.export',
        ],
        'LOGISTICS' => [
            'logistics.view', 'logistics.manage',
            'administrative_stock.view', 'administrative_stock.entry',
            'administrative_stock.exit', 'administrative_stock.inventory',
            'equipment.view', 'equipment.create', 'equipment.update',
            'equipment.delete', 'equipment.assign',
            'equipment.inventory', 'equipment.maintenance.manage',
            'equipment.decommission',
        ],
        // SUPPORT and MAINTENANCE contain distinct jobs. Their task-specific
        // permissions are copied to each account explicitly from its profile
        // template; they are never inherited globally by every role member.
        'SUPPORT' => [],
        'MAINTENANCE' => [],
        'RECEPTION' => [
            'reception.view',
            // ADR-114 — l'accueil organise la sortie d'un patient transféré.
            'transfers.view', 'transfers.manage',
            'employees.patient_lookup',
            'patient_staff_links.view', 'patient_staff_links.create',
            'address_entries.view', 'address_entries.create',
            'mutual_organizations.view', 'mutual_organizations.create',
            'partner_organizations.view',
            'patient_coverages.view', 'patient_coverages.create',
            'patient_coverage_documents.view', 'patient_coverage_documents.create',
            'visitors.view', 'visitors.create', 'visitors.close',
            'patients.view', 'patients.create', 'patients.update', 'patients.delete',
            'patients.medical_history.view', 'patients.medical_history.manage',
            // ADR-146 — « accouchement chez nous ou ailleurs ? » : l'accueil
            // retrouve le bébé dans l'arborescence de sa mère et lui ouvre son
            // dossier patient. Pas `newborns.medical_record.view` : poids,
            // Apgar et mode d'accouchement sont cliniques, et le Super
            // Administrateur les accorde depuis le portail s'il le décide
            // (ADR-064).
            'newborns.view', 'newborns.patient.create',
            // ADR-147 — l'accueil lit le module Hospitalisation : détail, dossier
            // et impression de la fiche. Aucune écriture : ni `hospitalization.update`,
            // ni `hospital_diet.record`, ni `medical_discharge.create`.
            'hospitalization.view',
            // ADR-104 — le rayon Pharmacie du panier d'arrivée. Lecture du
            // référentiel et de la disponibilité, plus la création de la
            // vente : exactement la paire que l'ADR-036 accorde déjà à
            // MEDICINE pour prescrire, sans aucun droit de mutation
            // `stock.*` — la Réception ne sort jamais un lot.
            'medicines.view', 'stock.availability.view', 'pharmacy.counter_sales.create',
            'episodes.view', 'episodes.create', 'episodes.update', 'episodes.mark_emergency', 'episodes.cancel',
            // CDC §33.3 — Réception contrôle le compte et prononce la
            // sortie payé comptant. Ni `debts.authorize` (renoncer à encaisser
            // un solde : la dérogation de §34.1 règle 6) ni
            // `debts.record_escape` (déclarer un patient évadé et créer une
            // créance, ADR-090 amendement du 2026-09-20) : les deux engagent
            // la clinique sur un montant et sont accordées par le Super
            // Administrateur — ADMINISTRATION par défaut, ou un chef de poste
            // Réception par son socle de rôle ou une exception individuelle
            // auditée (ADR-022, ADR-064).
            'episodes.settlement.view', 'episodes.administrative_exit', 'debts.view',
            'billing.view', 'billing.create', 'billing.validate',
            'billing.print',
            'payments.view', 'payments.create', 'payments.cancel',
            'cash.view', 'cash.open', 'cash.close', 'cash_registers.view',
            'receipts.view', 'receipts.print',
        ],
        'MEDICINE' => [
            'medical_record.view',
            'consultations.view', 'consultations.create', 'consultations.update',
            'consultations.delete',
            'diagnoses.view', 'diagnoses.create', 'diagnoses.update',
            'prescriptions.view', 'prescriptions.create', 'prescriptions.update',
            'medicines.view', 'stock.availability.view',
            'prescriptions.cancel', 'medical_discharge.create', 'patients.medical_history.view',
            'consultations.reopen',
            // ADR-107 — le registre des décès et son acte de constatation.
            'death_records.view', 'death_records.create',
            // ADR-113 — le séjour hospitalier et sa fiche de régime.
            'hospitalization.view', 'hospitalization.update', 'hospital_diet.record',
            // ADR-114 — transferts et file Pédiatrie.
            'transfers.view', 'transfers.manage', 'pediatrics.view', 'pediatrics.manage',
            // ADR-116 — journal de traitement du passage.
            'treatment_journal.view', 'treatment_journal.record',
            'clinical_protocols.view', 'clinical_protocols.manage',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
            // Le médecin peut requalifier ce passage précis pendant la
            // consultation ; ce droit ne modifie jamais le Patient.
            'episodes.mark_emergency',
            // La même projection de la fiche Soins que lit la Chirurgie
            // (ADR-048). `vitals.update` s'y ajoute depuis l'ADR-093 : le
            // médecin corrige une constante manifestement fausse — 32 °C au
            // lieu de 36,2 — sans attendre le retour du soignant qui l'a
            // saisie. Toujours pas de `care.update` : les actes, le matériel
            // et la transmission restent la parole des Soins.
            // `care.update` s'y ajoute à la demande explicite du propriétaire
            // (2026-09-15) : le médecin corrige la fiche Soins entière, pas
            // seulement les constantes. Conséquence assumée et signalée —
            // un acte facturable ajouté ici crée son BillableItem par le
            // circuit habituel (ADR-054). `care.create` reste exclu : une
            // fiche que personne n'a remplie ne se signe pas depuis une
            // consultation. `care_consumables.request` aussi : déclarer du
            // matériel sortirait du stock Pharmacie (ADR-072), et c'est le
            // geste de l'infirmier au chevet, pas celui du médecin.
            'care.view', 'vitals.view', 'vitals.update', 'care.update',
            // Phase B: a doctor may request Soins acts from a consultation,
            // never edit the resulting fiche itself.
            'care_orders.create', 'care_orders.view',
            // Paraclinique/orientation requests only — never the receiving
            // module's own create/manage permission (surgery.create stays
            // reserved to SURGERY; laboratory_results.create to LABORATORY).
            'paraclinical_requests.view', 'paraclinical_requests.archive',
            'laboratory_orders.create', 'laboratory_orders.view', 'laboratory_results.view',
            'imaging_orders.create', 'imaging_orders.view', 'imaging_results.create', 'imaging_results.update', 'imaging_templates.create', 'imaging_templates.update', 'imaging_templates.archive',
            'surgery.request', 'hospitalization.request', 'maternity.request',
            'transfer.request', 'pediatrics.request',
            // ADR-146 — le médecin qui reçoit un nouveau-né lit sa naissance.
            // Toujours pas `maternity.view` : le dossier obstétrical de la
            // mère reste au profil sage-femme (ADR-067).
            'newborns.view', 'newborns.medical_record.view',
        ],
        // Shared baseline for every paramedical profile. Anesthesia belongs
        // only to accounts explicitly assigned those permissions (normally
        // the ANESTHETIST profile), never to the whole NURSE role.
        'NURSE' => [
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update', 'medical_orders.view',
            'patients.medical_history.view',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
            // Reads a doctor's Soins request — never creates one itself.
            'care_orders.view',
            // ADR-072 — declares the consumables actually used, and follows
            // what Pharmacy served. Never `pharmacy.dispense`, never
            // `prescriptions.create`: Soins may not prescribe.
            'care_consumables.view', 'care_consumables.request',
            'care_consumables.cancel',
            // ADR-113 — la fiche de régime se remplit au lit du patient.
            'hospitalization.view', 'hospitalization.update', 'hospital_diet.record',
            // ADR-114 — les Soins accompagnent le départ d'un patient transféré.
            'transfers.view', 'transfers.manage',
            // ADR-116 — journal de traitement du passage.
            'treatment_journal.view', 'treatment_journal.record',
            // ADR-146 — la sage-femme renseigne la fiche du bébé ; toute
            // infirmière qui le prend en charge ensuite lit sa naissance.
            'newborns.view', 'newborns.medical_record.view',
        ],
        // SURGERY is the surgeon/operating-team baseline. Access to the
        // separate Anesthesia workspace is granted explicitly per account;
        // it is never implied by surgery.view (ADR-048).
        'SURGERY' => [
            'surgery.view', 'surgery.create', 'surgery.update', 'surgery.schedule',
            'surgery.preoperative.view', 'surgery.preoperative.validate',
            'surgery.intervention.create', 'surgery.intervention.update',
            'surgery.report.create', 'surgery.report.update', 'surgery.report.validate',
            'surgery.complications.create', 'surgery.discharge.create',
            'surgery.preparation.update', 'surgery.consumables.create',
            'surgery.care.create', 'surgery.postoperative_care.create',
            // The Soins worksheet is reused in read-only mode during block
            // preparation; no care/vitals/history mutation is granted here.
            'care.view', 'vitals.view', 'patients.medical_history.view',
            'episodes.view',
        ],
        // Pharmacy owns medication stock operations, never cash or payment.
        // medicines.create/update and every catalog/tariff mutation remain
        // reserved to Super Admin by ADR-024.
        'PHARMACY' => [
            'pharmacy.view', 'pharmacy.dispense', 'pharmacy.dispense.prepare_invoice', 'pharmacy.dispense.print',
            // ADR-104 — `pharmacy.counter_sales.create` quitte ce socle :
            // toute vente de médicament est désormais prise à la Réception,
            // sur un dossier patient et un passage. La Pharmacie continue de
            // délivrer, jamais de créer la vente.
            'pharmacy.return',
            'pharmacy.reports.view', 'pharmacy.reports.export',
            'prescriptions.view', 'medicines.view', 'medicine_categories.view',
            'stock.availability.view',
            'stock.view', 'stock.entry', 'stock.exit', 'stock.adjust',
            'stock.inventory', 'stock.validate', 'stock.transfer',
            'stock.approve', 'stock.import', 'stock.export',
            'stock.lots.view', 'stock.lots.create', 'stock.lots.update',
            'stock.expiration.view', 'stock.alerts.view',
            'stock.cost.view', 'stock.cost.record',
            // ADR-098 — suppliers and the whole procurement chain
            // (medicine_suppliers.*, supplier_catalogs.*,
            // medicine_supplier_offers.*, purchase_orders.*, goods_receipts.*,
            // supplier_invoices.*) are granted to no role by default. The
            // Super Admin grants them by name to the local accounts that
            // actually do this work.
            // ADR-072 — records the stock exit of consumables already used
            // at Soins. Separate from pharmacy.dispense, which stays bound
            // to a settled invoice (ADR-049).
            'care_consumables.view', 'care_consumables.serve',
        ],
        // Minimal follow-through only (request tracking + result entry) —
        // sample/analysis workflow itself remains unbuilt.
        'LABORATORY' => [
            'paraclinical_requests.view',
            'laboratory_orders.view', 'laboratory_results.view', 'laboratory_results.create',
        ],
    ];

    public function run(): void
    {
        $superAdminPermissionIds = config('rivo.site.type') === 'admin'
            ? Permission::query()->pluck('id')
            : collect();

        Role::query()->where('code', 'SUPER_ADMIN')->first()
            ?->permissions()->sync($superAdminPermissionIds);

        foreach (self::GRANTS as $code => $matchers) {
            $ids = $this->matchingPermissionIds($matchers);

            Role::query()->where('code', $code)->first()
                ?->permissions()->sync($ids);
        }
    }

    /**
     * A matcher ending in "." is a prefix glob (e.g. "patients." matches
     * "patients.view", "patients.medical_history.manage", ...). Anything
     * else must match exactly — "patients.view" must not also pull in
     * "patients.view_deleted" through a loose LIKE.
     *
     * @param  array<int, string>  $matchers
     */
    private function matchingPermissionIds(array $matchers): Collection
    {
        if ($matchers === []) {
            return collect();
        }

        return Permission::query()
            ->where(function ($query) use ($matchers) {
                foreach ($matchers as $matcher) {
                    if (str_ends_with($matcher, '.')) {
                        $query->orWhere('name', 'like', $matcher.'%');
                    } else {
                        $query->orWhere('name', $matcher);
                    }
                }
            })
            ->pluck('id');
    }
}
