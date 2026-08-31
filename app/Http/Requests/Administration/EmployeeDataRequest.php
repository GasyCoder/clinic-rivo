<?php

namespace App\Http\Requests\Administration;

use App\Enums\HrReferenceType;
use App\Enums\IdentityDocumentType;
use App\Enums\MaritalStatus;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

abstract class EmployeeDataRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'employee_number', 'first_name', 'last_name',
            'identity_document_number', 'profession', 'phone',
            'email', 'new_address_label', 'birth_place',
            'identity_document_issued_at', 'diploma', 'education_level',
            'children_details', 'badge', 'blouse', 'observation',
        ] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = str($value)->squish()->toString();
            $normalized[$field] = $value === '' ? null : $value;
        }

        if (isset($normalized['email'])) {
            $normalized['email'] = mb_strtolower($normalized['email']);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function employeeRules(?Employee $employee = null): array
    {
        return [
            'employee_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('employees', 'employee_number')->ignore($employee),
            ],
            'department_uuid' => [
                'nullable',
                'uuid',
                Rule::exists('hr_reference_values', 'uuid')->where(
                    function ($query) use ($employee): void {
                        $query->where('type', HrReferenceType::Department->value)
                            ->where(function ($available) use ($employee): void {
                                $available->where(function ($active): void {
                                    $active->where('active', true)->whereNull('deleted_at');
                                });

                                if ($employee?->department_id) {
                                    $available->orWhere('id', $employee->department_id);
                                }
                            });
                    },
                ),
            ],
            'job_title_uuid' => [
                'nullable',
                'uuid',
                Rule::exists('hr_reference_values', 'uuid')->where(
                    function ($query) use ($employee): void {
                        $query->where('type', HrReferenceType::JobTitle->value)
                            ->where(function ($available) use ($employee): void {
                                $available->where(function ($active): void {
                                    $active->where('active', true)->whereNull('deleted_at');
                                });

                                if ($employee?->job_title_id) {
                                    $available->orWhere('id', $employee->job_title_id);
                                }
                            });
                    },
                ),
            ],
            'civility' => ['nullable', new Enum(PatientCivility::class)],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'sex' => ['required', new Enum(PatientSex::class)],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'hire_date' => ['nullable', 'date'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'identity_document_type' => [
                'nullable',
                'required_with:identity_document_number',
                new Enum(IdentityDocumentType::class),
            ],
            'identity_document_number' => [
                'nullable',
                'required_with:identity_document_type',
                'string',
                'max:100',
            ],
            'identity_document_issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'identity_document_issued_at' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', new Enum(MaritalStatus::class)],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'diploma' => ['nullable', 'string', 'max:255'],
            'education_level' => ['nullable', 'string', 'max:255'],
            'children_details' => ['nullable', 'string', 'max:5000'],
            'badge' => ['nullable', 'string', 'max:255'],
            'blouse' => ['nullable', 'string', 'max:255'],
            'profession' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_entry_uuid' => [
                'nullable',
                'uuid',
                Rule::exists('address_entries', 'uuid')->where(
                    function ($query) use ($employee): void {
                        $query->where(function ($available): void {
                            $available->where('active', true)->whereNull('deleted_at');
                        });

                        // An employee already linked to an archived address
                        // keeps that historical reference until RH explicitly
                        // chooses another active address.
                        if ($employee?->address_entry_id) {
                            $query->orWhere('id', $employee->address_entry_id);
                        }
                    },
                ),
            ],
            'new_address_label' => ['nullable', 'string', 'max:255'],
            'observation' => ['nullable', 'string', 'max:5000'],
            'active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('address_entry_uuid') && $this->filled('new_address_label')) {
                $validator->errors()->add(
                    'new_address_label',
                    'Choisissez une adresse existante ou ajoutez-en une nouvelle, pas les deux.',
                );
            }
        }];
    }

    protected function addressPermissionsAreValid(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($this->filled('address_entry_uuid') && ! $user->can('address_entries.view')) {
            return false;
        }

        if ($this->filled('new_address_label') && ! $user->can('address_entries.create')) {
            return false;
        }

        return true;
    }

    public function attributes(): array
    {
        return [
            'employee_number' => 'matricule',
            'first_name' => 'prénom',
            'department_uuid' => 'département',
            'job_title_uuid' => 'fonction',
            'last_name' => 'nom',
            'sex' => 'sexe',
            'birth_date' => 'date de naissance',
            'hire_date' => 'date d’entrée',
            'birth_place' => 'lieu de naissance',
            'identity_document_type' => 'type de pièce d’identité',
            'identity_document_number' => 'numéro de pièce d’identité',
            'identity_document_issued_on' => 'date de délivrance de la pièce',
            'identity_document_issued_at' => 'lieu de délivrance de la pièce',
            'marital_status' => 'situation matrimoniale',
            'children_count' => 'nombre d’enfants',
            'diploma' => 'diplôme',
            'education_level' => 'niveau',
            'children_details' => 'détails des enfants',
            'badge' => 'badge',
            'blouse' => 'blouse',
            'profession' => 'fonction',
            'phone' => 'téléphone',
            'email' => 'adresse email',
            'address_entry_uuid' => 'adresse',
            'new_address_label' => 'nouvelle adresse',
            'observation' => 'observation',
            'active' => 'état actif',
        ];
    }

    public function messages(): array
    {
        return [
            'employee_number.unique' => 'Ce matricule est déjà utilisé, y compris par un dossier archivé.',
        ];
    }
}
