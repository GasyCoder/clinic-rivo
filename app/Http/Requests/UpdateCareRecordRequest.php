<?php

namespace App\Http\Requests;

use App\Enums\AllergySeverity;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\EpisodeOrientation;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCareRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        if (! $orientation instanceof EpisodeOrientation || ! $this->user()?->can('care.view')) {
            return false;
        }

        $orientation->loadMissing('episode.careRecord');
        $recordExists = $orientation->episode->careRecord !== null;

        $canWriteRecord = $this->user()->can($recordExists ? 'care.update' : 'care.create')
            && $this->user()->can($recordExists ? 'vitals.update' : 'vitals.create');

        if (! $canWriteRecord) {
            return false;
        }

        if ($this->hasAny(['allergy_note', 'allergy_uuids', 'allergen_reference_uuids', 'new_allergies'])
            && ! $this->user()->can('patients.medical_history.view')) {
            return false;
        }

        return (empty($this->input('new_allergies', []))
                && empty($this->input('allergen_reference_uuids', [])))
            || $this->user()->can('patients.medical_history.manage');
    }

    public function rules(): array
    {
        /** @var EpisodeOrientation|null $orientation */
        $orientation = $this->route('episodeOrientation');
        $orientation?->loadMissing('episode');
        $patientId = $orientation?->episode?->patient_id;
        $canViewAllergies = (bool) $this->user()?->can('patients.medical_history.view');
        $canManageAllergies = (bool) $this->user()?->can('patients.medical_history.manage');

        return [
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'height_cm' => ['nullable', 'numeric', 'min:20', 'max:250', 'decimal:0,2'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:500', 'decimal:0,2'],
            'allergy_note' => [Rule::prohibitedIf(! $canViewAllergies), 'nullable', 'string', 'max:2000'],
            'allergy_uuids' => [Rule::prohibitedIf(! $canViewAllergies), 'sometimes', 'array', 'max:20'],
            'allergy_uuids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('patient_allergies', 'uuid')->where(
                    fn (Builder $query) => $query
                        ->where('patient_id', $patientId)
                        ->whereNull('deleted_at'),
                ),
            ],
            'allergen_reference_uuids' => [Rule::prohibitedIf(! $canManageAllergies), 'sometimes', 'array', 'max:20'],
            'allergen_reference_uuids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('allergen_references', 'uuid')->where(
                    fn (Builder $query) => $query
                        ->where('active', true)
                        ->whereNull('deleted_at'),
                ),
            ],
            'new_allergies' => [Rule::prohibitedIf(! $canManageAllergies), 'sometimes', 'array', 'max:10'],
            'new_allergies.*.substance' => ['required', 'string', 'max:255'],
            'new_allergies.*.reaction' => ['nullable', 'string', 'max:1000'],
            'new_allergies.*.severity' => ['nullable', Rule::enum(AllergySeverity::class)],
            'smoker' => ['nullable', 'boolean'],
            'hospitalization_reason' => ['nullable', 'string', 'max:3000'],
            'hospitalized_at' => ['nullable', 'date'],
            'discharged_at' => ['nullable', 'date', 'after_or_equal:hospitalized_at'],
            'diagnostic_note' => ['nullable', 'string', 'max:3000'],
            'transmission_reason' => ['nullable', 'string', 'max:3000'],
            'procedures' => ['sometimes', 'array', 'max:30'],
            'procedures.*.catalog_item_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('catalog_items', 'uuid')->where(
                    fn (Builder $query) => $query
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Care->value)
                        ->whereNull('deleted_at'),
                ),
            ],
            'procedures.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999', 'decimal:0,2'],
            'procedures.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'discharged_at.after_or_equal' => 'La sortie ne peut pas précéder l’entrée d’hospitalisation.',
            'procedures.*.catalog_item_uuid.exists' => 'Un acte sélectionné est indisponible dans le référentiel Soins.',
            'procedures.*.catalog_item_uuid.distinct' => 'Un même acte ne peut être ajouté qu’une fois par enregistrement.',
            'allergy_uuids.*.exists' => 'Une allergie sélectionnée n’appartient pas à ce patient ou n’est plus disponible.',
            'allergen_reference_uuids.*.exists' => 'Cet allergène n’est plus disponible dans le référentiel.',
            'new_allergies.*.substance.required' => 'Indiquez la substance ou le produit allergène.',
        ];
    }
}
