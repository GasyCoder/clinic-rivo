<?php

namespace App\Http\Requests;

use App\Enums\IdentityDocumentType;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdatePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.update') ?? false;
    }

    public function rules(): array
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
