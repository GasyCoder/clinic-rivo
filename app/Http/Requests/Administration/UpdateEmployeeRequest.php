<?php

namespace App\Http\Requests\Administration;

use App\Models\Employee;

class UpdateEmployeeRequest extends EmployeeDataRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && ($this->user()?->can('update', $employee) ?? false)
            && $this->addressPermissionsAreValid();
    }

    /**
     * ADR-213 — la fiche s'enregistre section par section, automatiquement :
     * chaque règle ne s'applique qu'au champ réellement envoyé. Un champ omis
     * reste tel quel ; envoyé, il garde toute sa règle (le nom reste exigé).
     */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return collect($this->employeeRules($employee instanceof Employee ? $employee : null))
            ->map(fn (array $rules) => in_array('prohibited', $rules, true) ? $rules : ['sometimes', ...$rules])
            ->all() + ['_autosave' => ['sometimes', 'boolean']];
    }
}
