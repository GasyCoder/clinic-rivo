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
            // Une présence est un fait constaté : elle ne se saisit pas à l'avance (le planning
            // sert à prévoir). Dix minutes de marge pour l'horloge du poste.
            'started_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(10)->toDateTimeString()],
            'ended_at' => ['nullable', 'date', 'after:started_at', 'before_or_equal:'.now()->addMinutes(10)->toDateTimeString()],
            'observation' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'started_at.before_or_equal' => 'Une présence ne se saisit pas à l’avance : l’heure d’entrée est dans le futur.',
            'ended_at.before_or_equal' => 'L’heure de sortie est dans le futur.',
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
