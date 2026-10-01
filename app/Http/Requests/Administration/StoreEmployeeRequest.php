<?php

namespace App\Http\Requests\Administration;

use App\Models\Employee;

class StoreEmployeeRequest extends EmployeeDataRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('create', Employee::class) ?? false)
            && $this->addressPermissionsAreValid();
    }

    public function rules(): array
    {
        return [
            ...$this->employeeRules(),
            // ADR-194 — « Nouveau stagiaire » : après le dossier, son stage.
            // ADR-221 — « edit » : le dossier est créé à la première étape, la suite
            // du parcours s'enregistre toute seule dans la fiche.
            'after' => ['sometimes', 'nullable', 'in:internship,edit'],
            // ADR-221 — un stagiaire suit le même parcours, puis son stage.
            'internship' => ['sometimes', 'boolean'],
        ];
    }
}
