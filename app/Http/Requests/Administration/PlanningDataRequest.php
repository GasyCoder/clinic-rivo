<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'title' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'observation' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
