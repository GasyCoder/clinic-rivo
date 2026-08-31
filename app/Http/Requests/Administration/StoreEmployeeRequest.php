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
        return $this->employeeRules();
    }
}
