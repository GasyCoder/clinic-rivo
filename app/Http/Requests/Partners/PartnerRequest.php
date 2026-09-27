<?php

namespace App\Http\Requests\Partners;

use App\Enums\PartnerCategory;
use App\Enums\PartnerProfession;
use App\Enums\PatientSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * ADR-211 — la fiche d'un partenaire.
 *
 * Médical : nom, prénom, métier (et sa précision pour « Autre métier »), sexe
 * et date de naissance facultatifs — ils servent à ouvrir son dossier patient
 * sans ressaisie. Autre : un nom ou une identité. Téléphone, email, adresse et
 * remarque pour les deux. Les champs de l'autre catégorie sont ignorés, jamais
 * enregistrés.
 *
 * L'adresse se choisit dans le référentiel d'adresses du site, ou s'y ajoute
 * (`new_address_label`), comme pour un employé ou un patient : jamais un texte
 * libre à côté du référentiel.
 */
class PartnerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['name', 'last_name', 'first_name', 'profession_detail', 'phone', 'email', 'new_address_label', 'notes'])
            ->filter(fn (string $field) => is_string($this->input($field)))
            ->mapWithKeys(fn (string $field) => [$field => str($this->input($field))->squish()->toString() ?: null])
            ->all());
    }

    public function authorize(): bool
    {
        // Choisir une adresse du référentiel, ou en ajouter une, a son droit.
        if ($this->filled('address_entry_uuid') && ! $this->user()?->can('address_entries.view')) {
            return false;
        }

        return ! $this->filled('new_address_label') || (bool) $this->user()?->can('address_entries.create');
    }

    public function rules(): array
    {
        $isMedical = $this->input('category') === PartnerCategory::Medical->value;
        $isOtherProfession = $this->input('profession') === PartnerProfession::Other->value;

        return [
            'category' => ['required', new Enum(PartnerCategory::class)],

            'last_name' => [Rule::excludeIf(! $isMedical), 'required', 'string', 'max:255'],
            'first_name' => [Rule::excludeIf(! $isMedical), 'nullable', 'string', 'max:255'],
            'profession' => [Rule::excludeIf(! $isMedical), 'required', new Enum(PartnerProfession::class)],
            'profession_detail' => [Rule::excludeIf(! $isMedical || ! $isOtherProfession), 'required', 'string', 'max:100'],
            'sex' => [Rule::excludeIf(! $isMedical), 'nullable', new Enum(PatientSex::class)],
            'birth_date' => [Rule::excludeIf(! $isMedical), 'nullable', 'date', 'after:1900-01-01', 'before_or_equal:today'],

            'name' => [Rule::excludeIf($isMedical), 'required', 'string', 'max:255'],

            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_entry_uuid' => ['nullable', 'uuid'],
            'new_address_label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('address_entry_uuid') && $this->filled('new_address_label')) {
                $validator->errors()->add('new_address_label', 'Choisissez une adresse existante ou ajoutez-en une nouvelle, pas les deux.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'category' => 'catégorie',
            'last_name' => 'nom',
            'first_name' => 'prénom',
            'profession' => 'métier',
            'profession_detail' => 'précision du métier',
            'sex' => 'sexe',
            'birth_date' => 'date de naissance',
            'name' => 'nom ou identité',
            'phone' => 'téléphone',
            'email' => 'email',
            'address_entry_uuid' => 'adresse',
            'new_address_label' => 'nouvelle adresse',
            'notes' => 'remarque',
        ];
    }
}
