<?php

namespace App\Http\Requests;

use App\Enums\SurgicalTreatmentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostoperativeSurgicalTreatmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in([
                SurgicalTreatmentCategory::Serum->value,
                SurgicalTreatmentCategory::Antibiotic->value,
                SurgicalTreatmentCategory::AnalgesicNsaid->value,
                SurgicalTreatmentCategory::Other->value,
                SurgicalTreatmentCategory::Material->value,
            ])],
            'label' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'unit' => ['nullable', 'string', 'max:30'],
        ];
    }
}
