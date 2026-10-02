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

    /**
     * ADR-243 — la proposition affichée n'est jamais réservée : laissée telle quelle,
     * elle est recalculée à l'enregistrement. Deux créations parties de la même
     * page ne se disputent donc plus le même matricule ; un numéro changé à la main
     * reste celui du RH, et l'unicité le juge.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $proposed = trim((string) $this->input('employee_number_proposed', ''));
        if ($proposed !== '' && mb_strtoupper(trim((string) $this->input('employee_number', ''))) === mb_strtoupper($proposed)) {
            $this->merge(['employee_number' => '']);
        }
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
            'employee_number_proposed' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
