<?php

namespace App\Http\Requests;

use App\Enums\ArrivalPaymentChoice;
use App\Enums\CatalogItemType;
use App\Enums\IdentityDocumentType;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Opening a passage is enforced by the route middleware. Updating
        // the permanent record is a distinct capability and is required
        // only when the receptionist submits administrative corrections.
        if ($this->filled('patient_uuid') && $this->boolean('update_patient')) {
            if (! ($user?->can('patients.update') ?? false)) {
                return false;
            }
        }

        $catalogLines = $this->input('catalog_lines', []);

        if (is_array($catalogLines) && $catalogLines !== []) {
            if (! ($user?->can('billing.create') ?? false) || ! ($user?->can('billing.validate') ?? false)) {
                return false;
            }
        }

        if ($this->input('payment_choice') === ArrivalPaymentChoice::Now->value) {
            return $user?->can('payments.create') ?? false;
        }

        return true;
    }

    /**
     * Either `patient_uuid` (an existing patient the receptionist found and
     * picked) or the identity fields (a genuinely new patient) — never
     * both, never neither. first_name/civility/email are all optional —
     * only last_name, sex, and one of birth_date/age are required.
     */
    public function rules(): array
    {
        $arrivalRules = $this->arrivalRules();

        if ($this->filled('patient_uuid')) {
            $rules = [
                'patient_uuid' => [
                    'required',
                    'uuid',
                    Rule::exists('patients', 'uuid')->whereNull('deleted_at'),
                ],
                'is_emergency' => ['sometimes', 'boolean'],
                'update_patient' => ['sometimes', 'boolean'],
                ...$arrivalRules,
            ];

            return $this->boolean('update_patient')
                ? [...$rules, ...$this->patientRules()]
                : $rules;
        }

        return [
            ...$this->patientRules(),
            'is_emergency' => ['sometimes', 'boolean'],
            ...$arrivalRules,
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $catalogLines = $this->input('catalog_lines', []);
            $hasCatalogLines = is_array($catalogLines) && $catalogLines !== [];
            $canPrepareBilling = ($this->user()?->can('billing.create') ?? false)
                && ($this->user()?->can('billing.validate') ?? false);

            if ($hasCatalogLines && $this->boolean('defer_designation')) {
                $validator->errors()->add(
                    'defer_designation',
                    'Une prestation sélectionnée ne peut pas être marquée comme restant à définir.',
                );
            }

            if (! $hasCatalogLines && $this->filled('payment_choice')) {
                $validator->errors()->add(
                    'payment_choice',
                    'Choisissez un règlement uniquement lorsqu’une prestation est sélectionnée.',
                );
            }

            if ($canPrepareBilling
                && ! $this->boolean('is_emergency')
                && ! $hasCatalogLines
                && ! $this->boolean('defer_designation')) {
                $validator->errors()->add(
                    'catalog_lines',
                    'Sélectionnez au moins une prestation ou indiquez qu’elle sera définie après orientation.',
                );
            }
        }];
    }

    /** @return array<string, array<int, mixed>> */
    private function arrivalRules(): array
    {
        return [
            'defer_designation' => ['sometimes', 'boolean'],
            'catalog_lines' => ['nullable', 'array', 'max:50'],
            'catalog_lines.*.catalog_item_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('type', CatalogItemType::Service->value)
                    ->where('billable', true)),
            ],
            'catalog_lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'payment_choice' => [
                'nullable',
                'required_with:catalog_lines',
                new Enum(ArrivalPaymentChoice::class),
            ],
            'payment_method_id' => [
                Rule::requiredIf($this->input('payment_choice') === ArrivalPaymentChoice::Now->value),
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where('active', true),
            ],
            'payment_reference' => ['nullable', 'string', 'max:255'],
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
