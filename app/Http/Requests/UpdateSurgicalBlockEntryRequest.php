<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSurgicalBlockEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'height_cm' => ['nullable', 'numeric', 'min:20', 'max:250', 'decimal:0,2'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:500', 'decimal:0,2'],
            'temperature_celsius' => ['nullable', 'numeric', 'min:25', 'max:45', 'decimal:0,2'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300', 'required_with:blood_pressure_diastolic', 'gt:blood_pressure_diastolic'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200', 'required_with:blood_pressure_systolic', 'lt:blood_pressure_systolic'],
            'heart_rate' => ['nullable', 'integer', 'min:20', 'max:300'],
            'oxygen_saturation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'full_bath_completed' => ['nullable', 'boolean'],
            'weighing_completed' => ['nullable', 'boolean'],
            'peripheral_iv_count' => ['nullable', 'integer', Rule::in([1, 2])],
            'serum_name' => ['nullable', 'string', 'max:255'],
            'serum_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'serum_unit' => ['nullable', 'string', 'max:30'],
            'urinary_catheter_placed' => ['nullable', 'boolean'],
            'diuresis_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'diuresis_unit' => ['nullable', 'string', 'max:30'],
            'urine_appearance' => ['nullable', 'string', 'max:255'],
            'catheter_placed_at' => ['nullable', 'date'],
        ];
    }
}
