<?php

namespace App\Http\Requests\Hospitalization;

use App\Support\VitalSignRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * ADR-161 — un relevé de surveillance. Les bornes sont celles de la fiche
 * Soins, écrites une seule fois (`VitalSignRules`, ADR-093).
 */
class StoreVitalSignReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...Arr::only(VitalSignRules::rules(false), [
                'blood_pressure_systolic', 'blood_pressure_diastolic',
                'heart_rate', 'spo2', 'temperature_celsius',
            ]),
            'measured_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            ...VitalSignRules::messages(),
            'measured_at.before_or_equal' => 'Une mesure ne peut pas être datée dans le futur.',
        ];
    }
}
