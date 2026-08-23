<?php

namespace App\Http\Requests;

use App\Enums\IdentityDocumentType;
use App\Enums\MaritalStatus;
use App\Enums\MutualBeneficiaryType;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Enums\PatientType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Validates only the administrative part of an arrival.
 *
 * ADR-030 deliberately separates patient/episode creation from clinical
 * service selection and billing. Those operations have their own endpoint
 * after this request succeeds, so a cash failure cannot make Reception
 * re-enter the permanent patient record.
 */
class StoreArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($this->filled('patient_uuid')) {
            return $user->can('episodes.create');
        }

        if (! $user->can('patients.create') || ! $user->can('episodes.create')) {
            return false;
        }

        if ($this->input('patient_type') === PatientType::Staff->value
            && (! $user->can('employees.patient_lookup')
                || ! $user->can('patient_staff_links.create'))) {
            return false;
        }

        if ($this->input('patient_type') === PatientType::Mutual->value
            && ! $user->can('patient_coverages.create')) {
            return false;
        }

        if ($this->filled('address_entry_uuid')
            && ! $user->can('address_entries.view')) {
            return false;
        }

        if ($this->filled('new_address_label')
            && ! $user->can('address_entries.create')) {
            return false;
        }

        if ($this->hasFile('mutual_attachments')
            && ! $user->can('patient_coverage_documents.create')) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        if ($this->filled('patient_uuid')) {
            return [
                'patient_uuid' => [
                    'required',
                    'uuid',
                    Rule::exists('patients', 'uuid')->whereNull('deleted_at'),
                ],
                'is_emergency' => ['sometimes', 'boolean'],
                ...$this->emergencyContactRules(),
            ];
        }

        $type = (string) $this->input('patient_type');
        $isStaff = $type === PatientType::Staff->value;
        $isMutual = $type === PatientType::Mutual->value;

        $commonRule = fn (array $rules): array => [
            Rule::prohibitedIf($isStaff),
            ...$rules,
        ];

        return [
            'patient_type' => ['required', new Enum(PatientType::class)],
            'employee_uuid' => [
                Rule::requiredIf($isStaff),
                Rule::prohibitedIf(! $isStaff),
                'nullable',
                'uuid',
                Rule::exists('employees', 'uuid')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ],

            'first_name' => $commonRule(['nullable', 'string', 'max:255']),
            'last_name' => $commonRule([Rule::requiredIf(! $isStaff), 'nullable', 'string', 'max:255']),
            'birth_date' => $commonRule([
                Rule::requiredIf(! $isStaff && ! $this->filled('age')),
                'nullable',
                'date',
                'before_or_equal:today',
            ]),
            'age' => $commonRule([
                Rule::requiredIf(! $isStaff && ! $this->filled('birth_date')),
                'nullable',
                'integer',
                'min:0',
                'max:130',
            ]),
            'sex' => $commonRule([Rule::requiredIf(! $isStaff), 'nullable', new Enum(PatientSex::class)]),
            'civility' => $commonRule(['nullable', new Enum(PatientCivility::class)]),
            'identity_document_type' => $commonRule([
                'nullable',
                'required_with:identity_document_number',
                new Enum(IdentityDocumentType::class),
            ]),
            'identity_document_number' => $commonRule([
                'nullable',
                'required_with:identity_document_type',
                'string',
                'max:100',
            ]),
            'marital_status' => $commonRule(['nullable', new Enum(MaritalStatus::class)]),
            'children_count' => $commonRule(['nullable', 'integer', 'min:0', 'max:65535']),
            'profession' => $commonRule(['nullable', 'string', 'max:255']),
            'phone' => $commonRule(['nullable', 'string', 'max:50']),
            'email' => $commonRule(['nullable', 'email', 'max:255']),
            'address_entry_uuid' => $commonRule([
                'nullable',
                'uuid',
                Rule::exists('address_entries', 'uuid')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ]),
            'new_address_label' => $commonRule(['nullable', 'string', 'max:255']),
            ...$this->emergencyContactRules(),

            'mutual_organization_name' => [
                Rule::requiredIf($isMutual),
                Rule::prohibitedIf(! $isMutual),
                'nullable',
                'string',
                'max:255',
            ],
            'mutual_employer_name' => [
                Rule::requiredIf($isMutual),
                Rule::prohibitedIf(! $isMutual),
                'nullable',
                'string',
                'max:255',
            ],
            'mutual_beneficiary_type' => [
                Rule::requiredIf($isMutual),
                Rule::prohibitedIf(! $isMutual),
                'nullable',
                new Enum(MutualBeneficiaryType::class),
            ],
            'mutual_membership_number' => [
                Rule::requiredIf($isMutual),
                Rule::prohibitedIf(! $isMutual),
                'nullable',
                'string',
                'max:100',
            ],
            'mutual_attachments' => [
                Rule::prohibitedIf(! $isMutual),
                'nullable',
                'array',
                'max:5',
            ],
            'mutual_attachments.*' => [
                'bail',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:5120',
            ],

            'confirm_duplicate' => ['sometimes', 'boolean'],
            'is_emergency' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * ADR-034: the contact reachable for this patient belongs to the
     * passage, not the permanent record — asked the same way whether the
     * patient is new or returning, and never barred for STAFF (unlike the
     * HR-synced identity fields above, it isn't owned by the Employee
     * record).
     *
     * @return array<string, array<int, mixed>>
     */
    private function emergencyContactRules(): array
    {
        return [
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
            if ($validator->errors()->isNotEmpty() || $this->filled('patient_uuid')) {
                return;
            }

            if ($this->filled('address_entry_uuid') && $this->filled('new_address_label')) {
                $validator->errors()->add(
                    'new_address_label',
                    'Choisissez une adresse existante ou ajoutez-en une nouvelle, pas les deux.',
                );
            }

            if ($this->filled('birth_date') && $this->filled('age')) {
                $validator->errors()->add(
                    'age',
                    'Saisissez la date de naissance ou l’âge, pas les deux.',
                );
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'patient_type' => 'type de patient',
            'employee_uuid' => 'membre du personnel',
            'last_name' => 'nom',
            'first_name' => 'prénom(s)',
            'birth_date' => 'date de naissance',
            'age' => 'âge',
            'marital_status' => 'situation maritale',
            'children_count' => "nombre d'enfants",
            'profession' => 'profession',
            'address_entry_uuid' => 'adresse',
            'new_address_label' => 'nouvelle adresse',
            'emergency_contact_name' => 'nom de la personne à contacter',
            'emergency_contact_phone' => 'téléphone de la personne à contacter',
            'emergency_contact_relationship' => 'lien avec la personne à contacter',
            'emergency_contact_email' => 'email de la personne à contacter',
            'mutual_organization_name' => 'mutuelle',
            'mutual_employer_name' => 'entreprise',
            'mutual_beneficiary_type' => 'qualité du bénéficiaire',
            'mutual_membership_number' => 'numéro matricule',
            'mutual_attachments' => 'pièces de mutuelle',
            'mutual_attachments.*' => 'pièce de mutuelle',
        ];
    }
}
