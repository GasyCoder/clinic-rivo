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

    public function rules(): array
    {
        $employee = $this->route('employee');

        return $this->employeeRules($employee instanceof Employee ? $employee : null);
    }
}
