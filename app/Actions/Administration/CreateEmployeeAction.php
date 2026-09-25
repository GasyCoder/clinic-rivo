<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\User;
use App\Services\Administration\EmployeeAddressResolver;
use App\Services\Administration\EmployeeIdentityNormalizer;
use App\Services\Administration\EmployeeNumberAllocator;
use App\Services\Administration\HrReferenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateEmployeeAction
{
    public function __construct(
        private readonly EmployeeAddressResolver $addressResolver,
        private readonly EmployeeIdentityNormalizer $identityNormalizer,
        private readonly HrReferenceResolver $referenceResolver,
        private readonly EmployeeNumberAllocator $numbers,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): Employee
    {
        // ADR-190 : l'email de la fiche n'est posé que par la création de l'adresse
        // professionnelle ; une fiche enregistrée ne l'écrit ni ne l'efface jamais.
        unset($data['email']);

        Gate::forUser($actor)->authorize('create', Employee::class);

        return DB::transaction(function () use ($data, $actor): Employee {
            // ADR-191 — un matricule laissé vide reçoit le prochain du modèle du site.
            if (trim((string) ($data['employee_number'] ?? '')) === '') {
                $data['employee_number'] = $this->numbers->suggest();
            }

            $data = $this->identityNormalizer->normalize($data);
            $data = $this->referenceResolver->employeeData($data);
            $employee = Employee::query()->create($this->addressResolver->resolve($data, $actor));

            return $employee->load(['addressEntry', 'department', 'jobTitle']);
        });
    }
}
