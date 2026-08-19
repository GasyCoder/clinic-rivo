<?php

namespace App\Http\Requests;

use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreArrivalRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route's `can:episodes.create`
     * middleware, not here.
     */
    public function authorize(): bool
    {
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
            return [
                'patient_id' => ['required', 'integer', 'exists:patients,id'],
            ];
        }

        return [
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required_without:age', 'nullable', 'date', 'before_or_equal:today'],
            'age' => ['required_without:birth_date', 'nullable', 'integer', 'min:0', 'max:130'],
            'sex' => ['required', new Enum(PatientSex::class)],
            'civility' => ['nullable', new Enum(PatientCivility::class)],
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
