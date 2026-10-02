<?php

namespace App\Support\Hr;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-236 — ce qu'un dossier Employé porte ailleurs. Un dossier qui n'a servi nulle part
 * (saisi à tort, doublon) peut être supprimé définitivement ; dès qu'une seule ligne le
 * désigne, il ne s'archive plus que. Chaque table qui pointe vers `employees` est listée
 * ici — et un test vérifie que la liste n'en oublie aucune.
 */
class EmployeeUsage
{
    /** table => [colonne, ce que la ligne représente (singulier), (pluriel)] */
    public const REFERENCES = [
        'employment_contracts' => [['employee_id', 'contrat', 'contrats'], ['internship_supervisor_id', 'stage encadré', 'stages encadrés']],
        'attendance_records' => [['employee_id', 'présence', 'présences']],
        'leave_requests' => [['employee_id', 'demande de congé', 'demandes de congé'], ['interim_employee_id', 'intérim de congé', 'intérims de congé']],
        'planning_shifts' => [['employee_id', 'créneau de planning', 'créneaux de planning']],
        'hr_documents' => [['employee_id', 'document RH', 'documents RH']],
        'generated_documents' => [['employee_id', 'document généré', 'documents générés']],
        'employee_benefits' => [['employee_id', 'avantage de la fiche', 'avantages de la fiche']],
        'advantage_entries' => [['employee_id', 'avantage saisi', 'avantages saisis']],
        'salary_payments' => [['employee_id', 'paie', 'paies']],
        'bonus_awards' => [['employee_id', 'bonus', 'bonus']],
        'staff_debts' => [['employee_id', 'dette', 'dettes']],
        'staff_debt_repayments' => [['employee_id', 'remboursement de dette', 'remboursements de dette']],
        'staff_block_credit_movements' => [['employee_id', 'mouvement de crédit Bloc', 'mouvements de crédit Bloc']],
        'patient_staff_links' => [['employee_id', 'lien avec un dossier patient', 'liens avec un dossier patient']],
        'episode_staff_coverages' => [['employee_id', 'prise en charge Personnel', 'prises en charge Personnel']],
        'patient_referrals' => [['employee_id', 'recommandation de patient', 'recommandations de patient']],
        'professional_mailboxes' => [['employee_id', 'adresse email professionnelle', 'adresses email professionnelles']],
    ];

    /**
     * Tables qui pointent vers un employé sans être un usage : une liste de configuration
     * (le personnel d'une catégorie de bonus) qui part avec le dossier, ou une trace dont la
     * base efface déjà le lien (`SET NULL`).
     */
    /**
     * ADR-243 — le contrat d'un dossier saisi à tort (un stagiaire et son stage, en
     * particulier) part avec lui, tant qu'aucune pièce RH ni aucun document généré ne
     * le désigne : sans eux, il n'a jamais rien produit. Un stage encadré, lui, reste un
     * usage — il appartient au dossier d'un autre.
     */
    public const OWN_CONTRACTS = ['employment_contracts', 'employee_id'];

    public const DETACHED = [
        'bonus_category_employees' => 'employee_id',
        'staff_access_handover_items' => 'employee_id',
    ];

    /**
     * Ce qui empêche la suppression définitive, en mots : « 2 contrats », « un compte de
     * connexion relié ». Vide : le dossier n'a servi nulle part.
     *
     * @return list<string>
     */
    public static function blockers(Employee $employee): array
    {
        return self::blockersFor([$employee->getKey() => $employee->user_id])[$employee->getKey()] ?? [];
    }

    /**
     * La même chose pour toute une page, en une requête par colonne et non par dossier.
     *
     * @param  array<int, int|null>  $employees  id => user_id
     * @return array<int, list<string>>
     */
    public static function blockersFor(array $employees): array
    {
        $blockers = array_fill_keys(array_keys($employees), []);
        if ($blockers === []) {
            return [];
        }

        foreach ($employees as $id => $userId) {
            if ($userId !== null) {
                $blockers[$id][] = 'un compte de connexion relié';
            }
        }

        $ids = array_keys($employees);
        foreach (self::REFERENCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as [$column, $singular, $plural]) {
                $counts = DB::table($table)->whereIn($column, $ids)
                    ->when([$table, $column] === self::OWN_CONTRACTS, fn ($query) => $query->where(fn ($used) => $used
                        ->whereExists(fn ($pieces) => $pieces->selectRaw('1')->from('hr_documents')->whereColumn('hr_documents.employment_contract_id', 'employment_contracts.id'))
                        ->orWhereExists(fn ($documents) => $documents->selectRaw('1')->from('generated_documents')->whereColumn('generated_documents.employment_contract_id', 'employment_contracts.id'))))
                    ->groupBy($column)->selectRaw("{$column} as employee, count(*) as total")
                    ->pluck('total', 'employee');

                foreach ($counts as $employee => $count) {
                    $count = (int) $count;
                    $blockers[(int) $employee][] = $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
                }
            }
        }

        return $blockers;
    }

    /**
     * Retire ce qui part avec le dossier ; la base efface le reste (`SET NULL`).
     *
     * @return list<string> les références des contrats retirés avec lui
     */
    public static function detach(Employee $employee): array
    {
        DB::table('bonus_category_employees')->where('employee_id', $employee->getKey())->delete();

        $contracts = DB::table('employment_contracts')->where('employee_id', $employee->getKey());
        $removed = (clone $contracts)->pluck('uuid')->all();
        $contracts->delete();

        return $removed;
    }
}
