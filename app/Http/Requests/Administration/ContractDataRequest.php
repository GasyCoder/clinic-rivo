<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Models\EmploymentContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ContractDataRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['reference_number', 'observation', 'internship_school', 'internship_level'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $value = str($this->input($field))->squish()->toString();
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function contractRules(?EmploymentContract $contract = null): array
    {
        return [
            'employee_uuid' => [
                'required', 'uuid',
                Rule::exists('employees', 'uuid')->whereNull('deleted_at'),
            ],
            'contract_type_uuid' => [
                'required', 'uuid',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::ContractType->value)
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ],
            'reference_number' => [
                'nullable', 'string', 'max:255',
                Rule::unique('employment_contracts', 'reference_number')->ignore($contract),
            ],
            'signed_on' => ['nullable', 'date'],
            'starts_on' => ['required', 'date'],
            'trial_ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'observation' => ['nullable', 'string', 'max:5000'],
            // ADR-194 — le stage. L'action exige la filière quand le type est
            // un contrat de stage, et vide ces champs pour tout autre contrat.
            'internship_field_uuid' => [
                'nullable', 'uuid',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::InternshipField->value),
            ],
            'internship_school' => ['nullable', 'string', 'max:255'],
            'internship_level' => ['nullable', 'string', 'max:100'],
            'internship_supervisor_uuid' => [
                'nullable', 'uuid', 'different:employee_uuid',
                Rule::exists('employees', 'uuid')->whereNull('deleted_at'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_uuid' => 'employé',
            'contract_type_uuid' => 'type de contrat',
            'reference_number' => 'référence',
            'signed_on' => 'date de signature',
            'starts_on' => 'date de début',
            'trial_ends_on' => 'fin de période d’essai',
            'ends_on' => 'date de fin',
            'observation' => 'observation',
            'internship_field_uuid' => 'filière du stage',
            'internship_school' => 'école ou établissement',
            'internship_level' => 'niveau d’études',
            'internship_supervisor_uuid' => 'encadrant',
        ];
    }

    public function messages(): array
    {
        return [
            'internship_supervisor_uuid.different' => 'Le stagiaire ne peut pas être son propre encadrant.',
        ];
    }
}
