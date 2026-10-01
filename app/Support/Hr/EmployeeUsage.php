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

    /** Retire ce qui part avec le dossier ; la base efface le reste (`SET NULL`). */
    public static function detach(Employee $employee): void
    {
        DB::table('bonus_category_employees')->where('employee_id', $employee->getKey())->delete();
    }
}
