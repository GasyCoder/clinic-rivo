<?php

namespace App\Http\Requests;

use App\Enums\PatientAntecedentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientAntecedentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.medical_history.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:2000'],
            // Defaults to the patient's own history: that is what every row
            // recorded before this distinction meant.
            'type' => ['nullable', Rule::enum(PatientAntecedentType::class)],
        ];
    }

    public function antecedentType(): PatientAntecedentType
    {
        return PatientAntecedentType::tryFrom((string) $this->validated('type'))
            ?? PatientAntecedentType::Personal;
    }
}
