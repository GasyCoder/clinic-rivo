<?php

namespace App\Services\Administration;

use App\Enums\AccountKind;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * ADR-183 — relier un compte de connexion à la fiche Employé de la personne.
 *
 * Le lien (`employees.user_id`, ADR-168) se pose désormais à la création ou à
 * la modification du compte : « Personnel clinique » choisit une fiche,
 * « Externe » n'en a aucune. Il sert à lire le planning RH d'un compte — la
 * disponibilité d'un chirurgien au bloc — et ne donne aucun droit.
 *
 * Un compte, une fiche ; une fiche, un compte — archives comprises : deux
 * fiches pour un même compte donneraient deux plannings contradictoires.
 */
class EmployeeAccountLinker
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Applique le choix `account_kind` / `employee_uuid`. Sans `account_kind`,
     * rien ne change. À appeler dans la transaction qui écrit le compte.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(User $user, array $data, ?Authenticatable $actor): void
    {
        if (! array_key_exists('account_kind', $data) || blank($data['account_kind'])) {
            return;
        }

        $kind = AccountKind::from((string) $data['account_kind']);
        $current = Employee::withTrashed()->where('user_id', $user->id)->lockForUpdate()->first();

        if ($kind === AccountKind::External) {
            if ($current) {
                $this->unlink($user, $current, $actor);
            }

            return;
        }

        $employee = Employee::withTrashed()->where('uuid', $data['employee_uuid'] ?? null)->lockForUpdate()->first();

        if (! $employee) {
            throw ValidationException::withMessages(['employee_uuid' => 'Cette fiche employé n’existe plus.']);
        }

        if ($current && $current->is($employee)) {
            return;
        }

        // Un lien déjà posé survit à l'archivage de la fiche ; un nouveau lien
        // ne se pose que sur une personne qui travaille encore ici.
        if ($employee->trashed()) {
            throw ValidationException::withMessages([
                'employee_uuid' => "La fiche {$employee->employee_number} est archivée : restaurez-la dans Ressources humaines avant de lui relier un compte.",
            ]);
        }

        if (! $employee->active) {
            throw ValidationException::withMessages([
                'employee_uuid' => "La fiche {$employee->employee_number} est inactive : réactivez-la dans Ressources humaines avant de lui relier un compte.",
            ]);
        }

        if ($employee->user_id && (int) $employee->user_id !== (int) $user->id) {
            $holder = User::query()->find($employee->user_id);

            throw ValidationException::withMessages([
                'employee_uuid' => "La fiche {$employee->employee_number} est déjà reliée au compte « ".($holder?->name ?? 'inconnu').' ».',
            ]);
        }

        if ($current) {
            $this->unlink($user, $current, $actor);
        }

        $employee->forceFill(['user_id' => $user->id])->save();

        $this->auditor->record(
            'user.employee.link',
            entity: $user,
            newValues: ['employee' => $employee->employee_number, 'employee_uuid' => $employee->uuid],
            module: 'administration',
            actor: $actor,
        );
    }

    /**
     * Les fiches qu'un compte peut relier, pour l'assistant de compte : le
     * personnel en poste, avec le compte qui porte déjà chaque fiche. Le nom,
     * le matricule, la fonction et les coordonnées suffisent à reconnaître la
     * personne et à préremplir le compte ; rien d'autre n'est servi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function linkableEmployees(): array
    {
        return Employee::query()
            ->where('active', true)
            ->with(['jobTitle:id,label', 'department:id,label', 'user:id,uuid,name'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $employee) => [
                ...self::summary($employee),
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'account' => $employee->user ? ['uuid' => $employee->user->uuid, 'name' => $employee->user->name] : null,
            ])
            ->all();
    }

    /**
     * Ce qu'un compte dit de sa fiche, dans les listes de comptes.
     *
     * @return array<string, mixed>|null
     */
    public static function summary(?Employee $employee): ?array
    {
        if (! $employee) {
            return null;
        }

        return [
            'uuid' => $employee->uuid,
            'employee_number' => $employee->employee_number,
            'name' => trim(collect([$employee->first_name, $employee->last_name])->filter()->join(' ')),
            'job_title' => $employee->jobTitle?->label ?? $employee->profession,
            'department' => $employee->department?->label,
            'active' => (bool) $employee->active,
            'archived' => $employee->trashed(),
        ];
    }

    private function unlink(User $user, Employee $employee, ?Authenticatable $actor): void
    {
        $employee->forceFill(['user_id' => null])->save();

        $this->auditor->record(
            'user.employee.unlink',
            entity: $user,
            oldValues: ['employee' => $employee->employee_number, 'employee_uuid' => $employee->uuid],
            module: 'administration',
            actor: $actor,
        );
    }
}
