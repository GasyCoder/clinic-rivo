<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSurgicalPostoperativeObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'observed_at' => ['required', 'date'],
            'diuresis_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'diuresis_unit' => ['nullable', 'string', 'max:30'],
            'temperature_celsius' => ['nullable', 'numeric', 'min:25', 'max:45', 'decimal:0,2'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300', 'required_with:blood_pressure_diastolic', 'gt:blood_pressure_diastolic'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200', 'required_with:blood_pressure_systolic', 'lt:blood_pressure_systolic'],
            'heart_rate' => ['nullable', 'integer', 'min:20', 'max:300'],
            'oxygen_saturation' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
