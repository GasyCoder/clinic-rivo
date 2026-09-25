<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\User;
use App\Services\Administration\EmployeeAddressResolver;
use App\Services\Administration\EmployeeIdentityNormalizer;
use App\Services\Administration\HrReferenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateEmployeeAction
{
    public function __construct(
        private readonly EmployeeAddressResolver $addressResolver,
        private readonly EmployeeIdentityNormalizer $identityNormalizer,
        private readonly HrReferenceResolver $referenceResolver,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('create', Employee::class);

        return DB::transaction(function () use ($data, $actor): Employee {
            $data = $this->identityNormalizer->normalize($data);
            $data = $this->referenceResolver->employeeData($data);
            $employee = Employee::query()->create($this->addressResolver->resolve($data, $actor));

            return $employee->load(['addressEntry', 'department', 'jobTitle']);
        });
    }
}
