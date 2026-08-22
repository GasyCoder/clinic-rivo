<?php

namespace App\Http\Requests;

use App\Enums\IdentityDocumentType;
use App\Enums\MaritalStatus;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Enums\PatientType;
use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdatePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        if (! ($patient instanceof Patient)
            || $patient->patient_type === PatientType::Staff
            || ! ($this->user()?->can('patients.update') ?? false)) {
            return false;
        }

        if ($this->filled('address_entry_uuid')
            && ! $this->user()?->can('address_entries.view')) {
            return false;
        }

        if ($this->filled('new_address_label')
            && ! $this->user()?->can('address_entries.create')) {
            return false;
        }

        return true;
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
            'marital_status' => ['nullable', new Enum(MaritalStatus::class)],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'profession' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_entry_uuid' => [
                'nullable',
                'uuid',
                Rule::exists('address_entries', 'uuid')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ],
            'new_address_label' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->filled('birth_date') && $this->filled('age')) {
                $validator->errors()->add(
                    'age',
                    'Saisissez la date de naissance ou l’âge, pas les deux.',
                );
            }

            if ($this->filled('address_entry_uuid') && $this->filled('new_address_label')) {
                $validator->errors()->add(
                    'new_address_label',
                    'Choisissez une adresse existante ou ajoutez-en une nouvelle, pas les deux.',
                );
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'address_entry_uuid' => 'adresse',
            'new_address_label' => 'nouvelle adresse',
        ];
    }
}
