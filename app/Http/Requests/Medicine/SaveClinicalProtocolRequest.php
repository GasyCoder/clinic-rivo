<?php

namespace App\Http\Requests\Medicine;

use App\Enums\AdministrationRoute;
use App\Enums\PatientSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-111 — un protocole thérapeutique tel que le médecin le rédige.
 *
 * Les bornes de population sont vérifiées dans les deux sens : un protocole
 * « 14 à 1 ans » ne s'appliquerait à personne, et il ne le dirait pas.
 */
class SaveClinicalProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clinical_protocols.manage');
    }

    public function rules(): array
    {
        return [
            'diagnostic_catalog_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'indications' => ['nullable', 'array', 'max:30'],
            'indications.*' => ['nullable', 'string', 'max:120'],
            'min_age_years' => ['nullable', 'integer', 'min:0', 'max:130'],
            'max_age_years' => ['nullable', 'integer', 'min:0', 'max:130', 'gte:min_age_years'],
            'sex' => ['nullable', Rule::enum(PatientSex::class)],
            'min_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'max_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:400', 'gte:min_weight_kg'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['required', 'boolean'],
            // Une ordonnance type sans médicament n'aurait rien à proposer ;
            // un protocole qui ne sert qu'à suggérer le diagnostic en porte
            // quand même au moins un traitement à rédiger.
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.medicine_uuid' => ['required', 'uuid'],
            'lines.*.dosage' => ['nullable', 'string', 'max:255'],
            'lines.*.route' => ['nullable', Rule::in(AdministrationRoute::values())],
            'lines.*.frequency' => ['required', 'string', 'max:255'],
            'lines.*.duration' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'lines.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Un champ vide est une absence de borne, jamais « 0 ».
        $this->merge(collect(['min_age_years', 'max_age_years', 'min_weight_kg', 'max_weight_kg', 'sex'])
            ->mapWithKeys(fn (string $key) => [$key => $this->input($key) === '' ? null : $this->input($key)])
            ->all());
    }

    public function messages(): array
    {
        return [
            'diagnostic_catalog_uuid.required' => 'Choisissez le diagnostic que ce protocole traite.',
            'name.required' => 'Donnez un nom au protocole.',
            'lines.required' => 'Ajoutez au moins un médicament à l’ordonnance type.',
            'lines.min' => 'Ajoutez au moins un médicament à l’ordonnance type.',
            'lines.*.frequency.required' => 'Indiquez la fréquence de prise.',
            'max_age_years.gte' => 'L’âge maximal doit être supérieur ou égal à l’âge minimal.',
            'max_weight_kg.gte' => 'Le poids maximal doit être supérieur ou égal au poids minimal.',
        ];
    }
}
