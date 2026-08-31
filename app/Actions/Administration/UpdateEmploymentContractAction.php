<?php

namespace App\Actions\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\User;
use App\Services\Administration\HrReferenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateEmploymentContractAction
{
    public function __construct(private readonly HrReferenceResolver $references) {}

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
            unset($data['employee_uuid'], $data['contract_type_uuid']);

            $contract->fill([
                ...$data,
                'employee_id' => $employee->getKey(),
                'contract_type_id' => $type->getKey(),
            ])->save();

            return $contract->refresh()->load(['employee.department', 'employee.jobTitle', 'contractType']);
        });
    }
}
