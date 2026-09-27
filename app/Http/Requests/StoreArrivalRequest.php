<?php

namespace App\Http\Requests;

use App\Services\Settings\AppSettings;
use App\Support\Patients\PatientAgeRules;
use App\Enums\IdentityDocumentType;
use App\Enums\MaritalStatus;
use App\Enums\MutualBeneficiaryType;
use App\Enums\PartnerCategory;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Enums\PatientType;
use App\Enums\ReceptionCartKind;
use App\Enums\ReferralSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
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

        // ADR-211 — relier le dossier à la fiche RH ou à la fiche partenaire de
        // la même personne exige les droits de ces référentiels.
        if ($this->filled('employee_uuid')
            && (! $user->can('employees.patient_lookup') || ! $user->can('patient_staff_links.create'))) {
            return false;
        }

        if ($this->filled('partner_uuid') && ! $user->can('partner_organizations.view')) {
            return false;
        }

        // ADR-212 — noter qui a recommandé la clinique a son propre droit.
        if ($this->filled('referral') && ! $user->can('patient_referrals.create')) {
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
                // ADR-211 — le dossier trouvé est celui de cet employé (sa fiche
                // RH le synchronisera) ou de ce partenaire médical.
                'employee_uuid' => ['nullable', 'uuid', Rule::prohibitedIf($this->filled('partner_uuid')), $this->activeEmployeeRule()],
                'partner_uuid' => ['nullable', 'uuid', $this->medicalPartnerRule()],
                // ADR-212 — la recommandation se note à la création du dossier seulement.
                'referral' => ['prohibited'],
                // Emergency is a decision on the stable Episode UUID, never
                // an arrival flag carried before the passage exists.
                'is_emergency' => ['prohibited'],
                ...$this->emergencyContactRules(),
                ...$this->receptionDraftRules(),
            ];
        }

        $type = (string) $this->input('patient_type');
        $isStaff = $type === PatientType::Staff->value;
        $isMutual = $type === PatientType::Mutual->value;
        // ADR-177 — plus de troisième mode « Nouveau-né » : un bébé né ailleurs
        // est un nouveau patient, et la civilité « Enfant fille / garçon »
        // porte le profil enfant (ADR-146, amendement du 2026-09-22).
        // ADR-184 — l'âge aussi : un bébé ou un enfant, d'après les tranches
        // réglées pour ce site, n'a pas de champs d'adulte.
        $band = $isStaff ? null : PatientAgeRules::band(
            $this->input('birth_date'),
            $this->input('age'),
            app(AppSettings::class)->ageBands(),
        );
        $isChild = in_array($this->input('civility'), [
            PatientCivility::Girl->value,
            PatientCivility::Boy->value,
        ], true) || ($band?->isMinor() ?? false);

        $commonRule = fn (array $rules): array => [
            Rule::prohibitedIf($isStaff),
            ...$rules,
        ];
        $adultOnlyRule = fn (array $rules): array => [
            Rule::prohibitedIf($isStaff || $isChild),
            ...$rules,
        ];
        $civilityRule = fn (array $rules): array => [
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
                $this->activeEmployeeRule(),
            ],
            // ADR-211 — un nouveau dossier ouvert depuis la fiche d'un
            // partenaire médical : il lui est relié. Jamais avec une fiche RH.
            'partner_uuid' => [Rule::prohibitedIf($isStaff), 'nullable', 'uuid', $this->medicalPartnerRule()],
            ...$this->referralRules($isStaff),

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
            'civility' => $civilityRule(['nullable', new Enum(PatientCivility::class)]),
            'identity_document_type' => $adultOnlyRule([
                'nullable',
                'required_with:identity_document_number',
                new Enum(IdentityDocumentType::class),
            ]),
            'identity_document_number' => $adultOnlyRule([
                'nullable',
                'required_with:identity_document_type',
                'string',
                'max:100',
            ]),
            'marital_status' => $adultOnlyRule(['nullable', new Enum(MaritalStatus::class)]),
            'children_count' => $adultOnlyRule(['nullable', 'integer', 'min:0', 'max:65535']),
            'profession' => $adultOnlyRule(['nullable', 'string', 'max:255']),
            'phone' => $adultOnlyRule(['nullable', 'string', 'max:50']),
            'email' => $adultOnlyRule(['nullable', 'email', 'max:255']),
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
            'is_emergency' => ['prohibited'],
            ...$this->receptionDraftRules(),
        ];
    }

    /**
     * ADR-212 — qui a recommandé la clinique à ce nouveau patient : un membre du
     * personnel ou un partenaire par sa fiche, ou une autre personne par son
     * nom. Facultatif ; jamais pour un dossier ouvert depuis une fiche RH.
     *
     * @return array<string, array<int, mixed>>
     */
    private function referralRules(bool $isStaff): array
    {
        $source = $this->input('referral.source');

        return [
            'referral' => [Rule::prohibitedIf($isStaff), 'nullable', 'array'],
            'referral.source' => ['required_with:referral', new Enum(ReferralSource::class)],
            'referral.employee_uuid' => [
                Rule::excludeIf($source !== ReferralSource::Employee->value),
                'required', 'uuid', $this->activeEmployeeRule(),
            ],
            'referral.partner_uuid' => [
                Rule::excludeIf($source !== ReferralSource::Partner->value),
                'required', 'uuid',
                Rule::exists('partner_organizations', 'uuid')->where(fn ($query) => $query->where('active', true)->whereNull('deleted_at')),
            ],
            'referral.name' => [Rule::excludeIf($source !== ReferralSource::Other->value), 'required', 'string', 'max:255'],
            'referral.phone' => [Rule::excludeIf($source !== ReferralSource::Other->value), 'nullable', 'string', 'max:40'],
        ];
    }

    private function activeEmployeeRule(): Exists
    {
        return Rule::exists('employees', 'uuid')->where(fn ($query) => $query
            ->where('active', true)
            ->whereNull('deleted_at'));
    }

    /** ADR-211 — seul un partenaire Médical, actif, peut être la personne soignée. */
    private function medicalPartnerRule(): Exists
    {
        return Rule::exists('partner_organizations', 'uuid')->where(fn ($query) => $query
            ->where('category', PartnerCategory::Medical->value)
            ->where('active', true)
            ->whereNull('deleted_at'));
    }

    /** @return array<string, array<int, mixed>> */
    private function receptionDraftRules(): array
    {
        return [
            'reception_draft' => [
                'sometimes',
                'array:designation_deferred,catalog_lines',
                'required_array_keys:designation_deferred,catalog_lines',
            ],
            'reception_draft.designation_deferred' => ['required_with:reception_draft', 'boolean'],
            // The key must exist so the server can distinguish an omitted
            // browser payload from the intentional empty list used by the
            // "besoin à préciser" path.
            'reception_draft.catalog_lines' => ['array', 'max:50'],
            // ADR-104 — le panier porte deux rayons. Une ligne sans `kind`
            // est une prestation : c'est ce que contenaient les brouillons
            // antérieurs, et ne rien supposer d'autre évite de réinterpréter
            // une sélection déjà enregistrée.
            'reception_draft.catalog_lines.*.kind' => [
                'sometimes', Rule::enum(ReceptionCartKind::class),
            ],
            // La forme est vérifiée ici, l'éligibilité par
            // `ReceptionEstimateService` : les garde-fous d'un rayon
            // dépendent de son `kind`, et les recopier en règle de
            // validation les ferait diverger du résolveur qui chiffre
            // réellement la ligne.
            'reception_draft.catalog_lines.*.catalog_item_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('billable', true)),
            ],
            'reception_draft.catalog_lines.*.quantity' => [
                'required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2',
            ],
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
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->has('reception_draft')) {
                $deferred = $this->boolean('reception_draft.designation_deferred');
                $lines = $this->input('reception_draft.catalog_lines', []);

                if ($deferred && $lines !== []) {
                    $validator->errors()->add(
                        'reception_draft.catalog_lines',
                        'Un besoin à préciser ne doit contenir aucune ligne sélectionnée.',
                    );
                }

                if (! $deferred && $lines === []) {
                    $validator->errors()->add(
                        'reception_draft.catalog_lines',
                        'Sélectionnez au moins une prestation ou un médicament avant de créer le passage.',
                    );
                }
            }

            if ($this->filled('patient_uuid')) {
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

                return;
            }

            // ADR-184 — l'âge et la civilité doivent se dire la même chose.
            if ($this->input('patient_type') !== PatientType::Staff->value) {
                $violations = PatientAgeRules::violations(
                    $this->input('birth_date'),
                    $this->input('age'),
                    $this->input('civility'),
                    app(AppSettings::class)->ageBands(),
                );

                foreach ($violations as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'patient_type' => 'type de patient',
            'employee_uuid' => 'membre du personnel',
            'referral.source' => 'type de recommandant',
            'referral.employee_uuid' => 'membre du personnel qui a recommandé la clinique',
            'referral.partner_uuid' => 'partenaire qui a recommandé la clinique',
            'referral.name' => 'nom de la personne qui a recommandé la clinique',
            'referral.phone' => 'téléphone de la personne qui a recommandé la clinique',
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

    public function messages(): array
    {
        return [
            'is_emergency.prohibited' => 'Créez d’abord l’Épisode, puis classez ce passage précis en urgence depuis sa prise en charge.',
        ];
    }
}
