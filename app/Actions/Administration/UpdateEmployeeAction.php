<?php

namespace App\Actions\Administration;

use App\Actions\Patient\UpdatePatientAction;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\PatientStaffLink;
use App\Models\User;
use App\Services\Administration\EmployeeAddressResolver;
use App\Services\Administration\EmployeePatientIdentityMapper;
use App\Services\Administration\HrReferenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateEmployeeAction
{
    public function __construct(
        private readonly EmployeeAddressResolver $addressResolver,
        private readonly EmployeePatientIdentityMapper $patientIdentityMapper,
        private readonly UpdatePatientAction $updatePatient,
        private readonly HrReferenceResolver $referenceResolver,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Employee $employee, array $data, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('update', $employee);

        return DB::transaction(function () use ($employee, $data, $actor): Employee {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $data = $this->referenceResolver->employeeData($data);
            $data = $this->addressResolver->resolve($data, $actor, $employee);

            $staffLink = PatientStaffLink::query()
                ->active()
                ->where('employee_id', $employee->getKey())
                ->lockForUpdate()
                ->first();

            $candidateBirthDate = array_key_exists('birth_date', $data)
                ? $data['birth_date']
                : $employee->birth_date;

            if ($staffLink && empty($candidateBirthDate)) {
                throw ValidationException::withMessages([
                    'birth_date' => 'La date de naissance reste obligatoire tant que cet employé est relié à un dossier patient.',
                ]);
            }

            $employee->fill($data)->save();
            $employee->load(['addressEntry' => fn ($query) => $query->withTrashed()]);

            if ($staffLink) {
                $patient = Patient::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($staffLink->patient_id);

                $this->updatePatient->execute(
                    $patient,
                    $this->patientIdentityMapper->map($employee),
                    $actor,
                );
            }

            return $employee->refresh()->load([
                'addressEntry' => fn ($query) => $query->withTrashed(),
                'department' => fn ($query) => $query->withTrashed(),
                'jobTitle' => fn ($query) => $query->withTrashed(),
            ]);
        });
    }
}
