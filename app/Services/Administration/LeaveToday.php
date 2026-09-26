<?php

namespace App\Services\Administration;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ADR-198 — qui est en congé aujourd'hui : une demande acceptée dont la période
 * (premier jour → dernier jour demandé, inclus) couvre la date du jour.
 *
 * « Actif » dit que le dossier est en service ; un congé ne l'interrompt pas.
 * Les écrans montrent donc les deux, lus ici et nulle part ailleurs.
 */
final class LeaveToday
{
    /** Les congés acceptés en cours aujourd'hui. */
    public function leaves(Builder|Relation $query): Builder|Relation
    {
        $today = now()->toDateString();

        return $query->where('status', LeaveRequestStatus::Approved->value)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('returns_on', '>=', $today);
    }

    /** Les employés en congé aujourd'hui. */
    public function employees(Builder $employees): Builder
    {
        return $employees->whereHas('leaveRequests', fn ($leave) => $this->leaves($leave));
    }

    /**
     * Le congé en cours de chaque employé demandé : `employee_id => {until, type}`.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{until: ?string, type: ?string}>
     */
    public function byEmployee(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return $this->leaves(LeaveRequest::query())
            ->whereIn('employee_id', $employeeIds)
            ->with(['leaveType' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('returns_on')
            ->get()
            ->unique('employee_id')
            ->mapWithKeys(fn (LeaveRequest $leave) => [$leave->employee_id => [
                'until' => $leave->returns_on?->toDateString(),
                'type' => $leave->leaveType?->label,
            ]])
            ->all();
    }

    public function count(): int
    {
        return $this->leaves(LeaveRequest::query())->distinct('employee_id')->count('employee_id');
    }
}
