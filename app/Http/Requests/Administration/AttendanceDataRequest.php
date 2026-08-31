<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AttendanceDataRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->exists('observation') && is_string($this->input('observation'))) {
            $value = str($this->input('observation'))->squish()->toString();
            $this->merge(['observation' => $value === '' ? null : $value]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_uuid' => [
                'required', 'uuid',
                Rule::exists('employees', 'uuid')->whereNull('deleted_at'),
            ],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after:started_at'],
            'observation' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_uuid' => 'employé',
            'started_at' => 'heure d’entrée',
            'ended_at' => 'heure de sortie',
            'observation' => 'observation',
        ];
    }
}
