<?php

namespace App\Actions\Administration;

use App\Enums\PatientType;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\PatientStaffLink;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkPatientToEmployeeAction
{
    public function execute(Patient $patient, Employee $employee, User $actor): PatientStaffLink
    {
        if ($actor->cannot('patient_staff_links.create')) {
            throw new AuthorizationException('Vous ne pouvez pas relier un patient à un employé.');
        }

        return DB::transaction(function () use ($patient, $employee, $actor) {
            $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($patient->patient_type !== PatientType::Staff) {
                throw ValidationException::withMessages([
                    'patient_type' => 'Le dossier doit être de type Personnel.',
                ]);
            }

            if (! $employee->isAvailableForPatientLink()) {
                throw ValidationException::withMessages([
                    'employee_uuid' => 'Cet employé est inactif ou archivé.',
                ]);
            }

            $existing = PatientStaffLink::query()
                ->active()
                ->where(function ($query) use ($patient, $employee) {
                    $query->where('patient_id', $patient->id)
                        ->orWhere('employee_id', $employee->id);
                })
                ->lockForUpdate()
                ->first();

            if ($existing?->patient_id === $patient->id && $existing?->employee_id === $employee->id) {
                return $existing;
            }

            if ($existing) {
                throw ValidationException::withMessages([
                    'employee_uuid' => 'Ce patient ou cet employé est déjà relié à un autre dossier actif.',
                ]);
            }

            return PatientStaffLink::create([
                'patient_id' => $patient->id,
                'employee_id' => $employee->id,
                'linked_by' => $actor->id,
                'linked_at' => now(),
            ]);
        });
    }
}
