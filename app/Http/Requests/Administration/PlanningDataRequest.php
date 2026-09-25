<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Enums\PlanningShiftKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

abstract class PlanningDataRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['title', 'observation'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $value = str($this->input($field))->squish()->toString();
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'employee_uuid' => ['required', 'uuid', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'department_uuid' => [
                'nullable', 'uuid',
                Rule::exists('hr_reference_values', 'uuid')
                    ->where('type', HrReferenceType::Department->value)
                    ->where('active', true)
                    ->whereNull('deleted_at'),
            ],
            // ADR-194 — service (planning du personnel) ou garde. Omis : service.
            'kind' => ['sometimes', new Enum(PlanningShiftKind::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'observation' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_uuid' => 'employé',
            'department_uuid' => 'département',
            'kind' => 'type de créneau',
            'title' => 'objet',
            'starts_at' => 'début',
            'ends_at' => 'fin',
            'observation' => 'observation',
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after' => 'La fin doit être après le début.',
        ];
    }
}
