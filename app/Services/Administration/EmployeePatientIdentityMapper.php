<?php

namespace App\Services\Administration;

use App\Models\Employee;

class EmployeePatientIdentityMapper
{
    /** @return array<string, mixed> */
    public function map(Employee $employee): array
    {
        return [
            'civility' => $employee->civility?->value,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'birth_date' => $employee->birth_date?->toDateString(),
            'sex' => $employee->sex->value,
            'identity_document_type' => $employee->identity_document_type?->value,
            'identity_document_number' => $employee->identity_document_number,
            'marital_status' => $employee->marital_status?->value,
            'children_count' => $employee->children_count,
            'profession' => $employee->profession,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'address' => $employee->addressEntry?->label ?? $employee->address,
            'address_entry_id' => $employee->address_entry_id,
        ];
    }
}
