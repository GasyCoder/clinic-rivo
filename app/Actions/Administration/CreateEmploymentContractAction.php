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

class CreateEmploymentContractAction
{
    public function __construct(
        private readonly HrReferenceResolver $references,
        private readonly InternshipContractResolver $internships,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): EmploymentContract
    {
        Gate::forUser($actor)->authorize('create', EmploymentContract::class);

        return DB::transaction(function () use ($data): EmploymentContract {
            $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
            $type = $this->references->resolve(
                $data['contract_type_uuid'],
                HrReferenceType::ContractType,
                'contract_type_uuid',
            );
            // ADR-194 — la filière, l'école et l'encadrant d'un contrat de stage.
            $internship = $this->internships->resolve($type, $data);

            $data = Arr::except($data, ['employee_uuid', 'contract_type_uuid', ...InternshipContractResolver::KEYS]);

            $contract = EmploymentContract::query()->create([
                ...$data,
                ...$internship,
                'employee_id' => $employee->getKey(),
                'contract_type_id' => $type->getKey(),
            ]);

            return $contract->load(['employee.department', 'employee.jobTitle', 'contractType', 'internshipField', 'internshipSupervisor']);
        });
    }
}
