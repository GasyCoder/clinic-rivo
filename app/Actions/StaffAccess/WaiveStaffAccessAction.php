<?php

namespace App\Actions\StaffAccess;

use App\Models\Employee;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Validation\ValidationException;

/**
 * ADR-197 — site : « aucun accès nécessaire » pour un employé (agent d'entretien,
 * gardien sans poste…). Il quitte la liste « À créer » du Super Admin ; rien
 * n'est effacé, et l'état se lève à tout moment.
 */
final class WaiveStaffAccessAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function waive(Employee $employee, string $reason, CatalogActor $actor): void
    {
        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['employee' => 'Cet employé a déjà un compte RIVO.']);
        }

        $identity = GrantStaffAccessAction::identity($actor);
        $employee->forceFill([
            'access_waived_at' => now(),
            'access_waived_reason' => $reason,
            'access_waived_by_name' => $identity['name'],
        ])->save();

        $this->auditor->record('staff_access.waive', entity: $employee, newValues: ['reason' => $reason], module: 'administration', actor: $actor->user());
    }

    public function restore(Employee $employee, CatalogActor $actor): void
    {
        if ($employee->access_waived_at === null) {
            return;
        }

        $employee->forceFill(['access_waived_at' => null, 'access_waived_reason' => null, 'access_waived_by_name' => null])->save();
        $this->auditor->record('staff_access.unwaive', entity: $employee, module: 'administration', actor: $actor->user());
    }
}
