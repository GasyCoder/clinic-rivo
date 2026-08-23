<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purpose-specific employee directory for Reception. It never exposes user
 * permissions, HR contracts or other future confidential personnel fields.
 * The route is intentionally wired by the Reception workflow separately.
 */
class EmployeePatientLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('employees.patient_lookup'), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $search = trim($validated['q']);

        $employees = Employee::query()
            ->with([
                'addressEntry:id,uuid,label',
                'activePatientLink.patient:id,uuid,patient_number,first_name,last_name',
            ])
            ->where('active', true)
            ->where(function ($query) use ($search) {
                $query->where('employee_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('identity_document_number', 'like', "%{$search}%");
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(10)
            ->get()
            ->map(fn (Employee $employee) => [
                'uuid' => $employee->uuid,
                'employee_number' => $employee->employee_number,
                'civility' => $employee->civility?->value,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'sex' => $employee->sex->value,
                'birth_date' => $employee->birth_date?->toDateString(),
                'identity_document_type' => $employee->identity_document_type?->value,
                'identity_document_number' => $employee->identity_document_number,
                'marital_status' => $employee->marital_status?->value,
                'children_count' => $employee->children_count,
                'profession' => $employee->profession,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'address' => $employee->address,
                'address_entry' => $employee->addressEntry ? [
                    'uuid' => $employee->addressEntry->uuid,
                    'label' => $employee->addressEntry->label,
                ] : null,
                'linked_patient' => $employee->activePatientLink?->patient ? [
                    'uuid' => $employee->activePatientLink->patient->uuid,
                    'patient_number' => $employee->activePatientLink->patient->patient_number,
                    'first_name' => $employee->activePatientLink->patient->first_name,
                    'last_name' => $employee->activePatientLink->patient->last_name,
                ] : null,
            ]);

        return response()->json(['data' => $employees]);
    }
}
