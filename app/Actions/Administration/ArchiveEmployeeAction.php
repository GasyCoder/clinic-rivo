<?php

namespace App\Actions\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ArchiveEmployeeAction
{
    public function execute(Employee $employee, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $employee);

        DB::transaction(function () use ($employee, $reason): void {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            // Une session de présence encore ouverte laisserait la personne « présente » sans
            // fin, une fois partie : on la clôt d'abord (heure de sortie), puis on archive.
            $open = AttendanceRecord::query()->where('employee_id', $employee->getKey())->whereNull('ended_at')->orderBy('started_at')->first();
            if ($open !== null) {
                throw ValidationException::withMessages([
                    'reason' => sprintf('Sa session de présence du %s est encore ouverte : enregistrez son heure de sortie avant d’archiver le dossier.', $open->started_at->format('d/m/Y à H:i')),
                ]);
            }
            $employee->delete_reason = str($reason)->squish()->toString();
            $employee->delete();
        });
    }
}
