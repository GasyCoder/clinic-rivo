<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\User;
use App\Services\Administration\EmployeePhotoStore;
use App\Services\Audit\Auditor;
use App\Support\Hr\EmployeeUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ADR-236 — supprimer définitivement un dossier employé saisi à tort (doublon, erreur
 * d'import). Exception étroite à ADR-009/010, sur le modèle de l'ADR-062 :
 *
 *   déjà archivé         on archive d'abord, avec un motif ; on ne détruit qu'ensuite
 *   n'a servi nulle part  ni compte de connexion, ni contrat, présence, congé, paie, dette,
 *                         document, lien patient… (EmployeeUsage) — sinon il reste archivé
 *
 * Ce que le dossier était reste dans l'audit ; sa photo part avec lui.
 */
class ForceDeleteEmployeeAction
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly EmployeePhotoStore $photos,
    ) {}

    public function execute(Employee $employee, User $actor): void
    {
        Gate::forUser($actor)->authorize('forceDelete', $employee);

        $photo = DB::transaction(function () use ($employee): ?string {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $employee->trashed()) {
                throw ValidationException::withMessages(['employee' => 'Archivez d’abord ce dossier, avec un motif : seul un dossier archivé se supprime définitivement.']);
            }

            $blockers = EmployeeUsage::blockers($employee);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['employee' => sprintf(
                    'Le dossier %s a servi (%s) : il reste archivé, on ne détruit pas un historique.',
                    $employee->employee_number,
                    implode(', ', $blockers),
                )]);
            }

            $this->auditor->record('employee.force_delete', $employee, oldValues: [
                'employee_number' => $employee->employee_number,
                'name' => trim("{$employee->last_name} {$employee->first_name}"),
                'birth_date' => $employee->birth_date?->toDateString(),
                'delete_reason' => $employee->delete_reason,
            ], module: 'administration');

            EmployeeUsage::detach($employee);
            $employee->forceDelete();

            return $employee->photo_path;
        });

        $this->photos->delete($photo);
    }
}
