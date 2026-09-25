<?php

namespace App\Actions\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\User;
use App\Services\Administration\HrReferenceResolver;
use App\Services\Administration\InternshipContractResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateEmploymentContractAction
{
    public function __construct(
        private readonly HrReferenceResolver $references,
        private readonly InternshipContractResolver $internships,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EmploymentContract $contract, array $data, User $actor): EmploymentContract
    {
        Gate::forUser($actor)->authorize('update', $contract);

        return DB::transaction(function () use ($contract, $data): EmploymentContract {
            $contract = EmploymentContract::query()->lockForUpdate()->findOrFail($contract->getKey());
            $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
            $type = $this->references->resolve(
                $data['contract_type_uuid'],
                HrReferenceType::ContractType,
                'contract_type_uuid',
            );
            // ADR-194 — la filière déjà enregistrée reste acceptée même archivée.
            $internship = $this->internships->resolve($type, $data, $contract);

            $data = Arr::except($data, ['employee_uuid', 'contract_type_uuid', ...InternshipContractResolver::KEYS]);

            $contract->fill([
                ...$data,
                ...$internship,
                'employee_id' => $employee->getKey(),
                'contract_type_id' => $type->getKey(),
            ])->save();

            return $contract->refresh()->load(['employee.department', 'employee.jobTitle', 'contractType', 'internshipField', 'internshipSupervisor']);
        });
    }
}
