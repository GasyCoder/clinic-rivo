<?php

namespace App\Http\Requests;

use App\Enums\IdentityDocumentType;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Opening a passage is enforced by the route middleware. Updating
        // the permanent record is a distinct capability and is required
        // only when the receptionist submits administrative corrections.
        if ($this->filled('patient_id') && $this->boolean('update_patient')) {
            return $this->user()?->can('patients.update') ?? false;
        }

        return true;
    }

    /**
     * Either `patient_id` (an existing patient the receptionist found and
     * picked) or the identity fields (a genuinely new patient) — never
     * both, never neither. first_name/civility/email are all optional —
     * only last_name, sex, and one of birth_date/age are required.
     */
    public function rules(): array
    {
        if ($this->filled('patient_id')) {
            $rules = [
                'patient_id' => ['required', 'integer', 'exists:patients,id'],
                'is_emergency' => ['sometimes', 'boolean'],
                'update_patient' => ['sometimes', 'boolean'],
            ];

            return $this->boolean('update_patient')
                ? [...$rules, ...$this->patientRules()]
                : $rules;
        }

        return [
            ...$this->patientRules(),
            'is_emergency' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function patientRules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required_without:age', 'nullable', 'date', 'before_or_equal:today'],
            'age' => ['required_without:birth_date', 'nullable', 'integer', 'min:0', 'max:130'],
            'sex' => ['required', new Enum(PatientSex::class)],
            'civility' => ['nullable', new Enum(PatientCivility::class)],
            'identity_document_type' => ['nullable', 'required_with:identity_document_number', new Enum(IdentityDocumentType::class)],
            'identity_document_number' => ['nullable', 'required_with:identity_document_type', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
