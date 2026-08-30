<?php

namespace App\Http\Requests;

use App\Enums\AllergySeverity;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\EpisodeOrientation;
use App\Support\CareWorkflow;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCareRecordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('allergy_uuids') || ! is_array($this->input('allergy_uuids'))) {
            return;
        }

        $orientation = $this->route('episodeOrientation');

        if (! $orientation instanceof EpisodeOrientation) {
            return;
        }

        $orientation->loadMissing(['episode.careRecord', 'episode.patient.allergies']);
        $activeUuids = $orientation->episode->patient->allergies->pluck('uuid');
        $missingHistoricalUuids = collect($orientation->episode->careRecord?->allergy_snapshot ?? [])
            ->pluck('uuid')
            ->filter()
            ->diff($activeUuids);

        $this->merge([
            'allergy_uuids' => collect($this->input('allergy_uuids'))
                ->reject(fn ($uuid) => $missingHistoricalUuids->contains($uuid))
                ->values()
                ->all(),
        ]);
    }

    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        if (! $orientation instanceof EpisodeOrientation || ! $this->user()?->can('care.view')) {
            return false;
        }

        $orientation->loadMissing('episode.careRecord');
        $recordExists = $orientation->episode->careRecord !== null;

        $canWriteRecord = $this->user()->can($recordExists ? 'care.update' : 'care.create');

        if (! $canWriteRecord) {
            return false;
        }

        $submitsVitals = collect([
            'blood_group',
            'blood_pressure_systolic', 'blood_pressure_diastolic',
            'heart_rate', 'spo2',
            'temperature_celsius', 'known_diabetes', 'diabetes_note',
            'height_cm', 'weight_kg', 'smoker',
        ])
            ->contains(fn (string $field) => $this->input($field) !== null
                && $this->input($field) !== '');

        if ($submitsVitals && ! $this->user()->can($recordExists ? 'vitals.update' : 'vitals.create')) {
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

    public function rules(CareWorkflow $careWorkflow): array
    {
        /** @var EpisodeOrientation|null $orientation */
        $orientation = $this->route('episodeOrientation');
        $orientation?->loadMissing('episode');
        $patientId = $orientation?->episode?->patient_id;
        $canViewAllergies = (bool) $this->user()?->can('patients.medical_history.view');
        $canManageAllergies = (bool) $this->user()?->can('patients.medical_history.manage');
        $canTransmitToMedicine = $orientation
            ? $careWorkflow->expectsMedicalTransmission($orientation->episode)
            : false;

        return [
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'blood_pressure_systolic' => [
                'nullable', 'integer', 'min:40', 'max:300',
                'required_with:blood_pressure_diastolic',
                'gt:blood_pressure_diastolic',
            ],
            'blood_pressure_diastolic' => [
                'nullable', 'integer', 'min:20', 'max:200',
                'required_with:blood_pressure_systolic',
                'lt:blood_pressure_systolic',
            ],
            'heart_rate' => ['nullable', 'integer', 'min:20', 'max:250'],
            'spo2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'temperature_celsius' => ['nullable', 'numeric', 'min:25', 'max:45', 'decimal:0,2'],
            'known_diabetes' => ['nullable', 'boolean'],
            'diabetes_note' => [
                Rule::prohibitedIf($this->boolean('known_diabetes') !== true),
                'nullable', 'string', 'max:1000',
            ],
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
            'hospitalization_reason' => ['prohibited'],
            'hospitalized_at' => ['prohibited'],
            'discharged_at' => ['prohibited'],
            'diagnostic_note' => [Rule::prohibitedIf(! $canTransmitToMedicine), 'nullable', 'string', 'max:3000'],
            'transmission_reason' => [Rule::prohibitedIf(! $canTransmitToMedicine), 'nullable', 'string', 'max:3000'],
            'no_procedure_reason' => ['nullable', 'string', 'max:1000'],
            'orient_to_medicine' => ['sometimes', 'boolean'],
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
            'procedures.*.allergy_checked' => ['sometimes', 'boolean'],
            'procedures.*.care_order_item_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'hospitalization_reason.prohibited' => 'La décision d’hospitalisation doit être enregistrée dans le module médical.',
            'hospitalized_at.prohibited' => 'L’entrée d’hospitalisation est enregistrée par le workflow d’hospitalisation.',
            'discharged_at.prohibited' => 'La sortie relève de la décision médicale et ne peut pas être saisie dans la fiche Soins.',
            'diagnostic_note.prohibited' => 'Ce passage ne prévoit pas de transmission vers Médecine.',
            'transmission_reason.prohibited' => 'Ce passage se termine aux Soins et ne prévoit pas de transmission vers Médecine.',
            'procedures.*.catalog_item_uuid.exists' => 'Un acte sélectionné est indisponible dans le référentiel Soins.',
            'procedures.*.catalog_item_uuid.distinct' => 'Un même acte ne peut être ajouté qu’une fois par enregistrement.',
            'procedures.*.allergy_checked.boolean' => 'La vérification du statut allergique doit être confirmée explicitement.',
            'allergy_uuids.*.exists' => 'Une allergie sélectionnée n’appartient pas à ce patient ou n’est plus disponible.',
            'allergen_reference_uuids.*.exists' => 'Cet allergène n’est plus disponible dans le référentiel.',
            'new_allergies.*.substance.required' => 'Indiquez la substance ou le produit allergène.',
            'blood_pressure_systolic.required_with' => 'Renseignez la pression systolique.',
            'blood_pressure_diastolic.required_with' => 'Renseignez la pression diastolique.',
            'blood_pressure_systolic.gt' => 'La pression systolique doit être supérieure à la pression diastolique.',
            'blood_pressure_diastolic.lt' => 'La pression diastolique doit être inférieure à la pression systolique.',
            'temperature_celsius.min' => 'La température doit être comprise entre 25 et 45 °C.',
            'temperature_celsius.max' => 'La température doit être comprise entre 25 et 45 °C.',
            'blood_pressure_systolic.min' => 'La pression systolique doit être comprise entre 40 et 300 mmHg.',
            'blood_pressure_systolic.max' => 'La pression systolique doit être comprise entre 40 et 300 mmHg.',
            'blood_pressure_diastolic.min' => 'La pression diastolique doit être comprise entre 20 et 200 mmHg.',
            'blood_pressure_diastolic.max' => 'La pression diastolique doit être comprise entre 20 et 200 mmHg.',
            'heart_rate.min' => 'La fréquence cardiaque doit être comprise entre 20 et 250 btt/mn.',
            'heart_rate.max' => 'La fréquence cardiaque doit être comprise entre 20 et 250 btt/mn.',
            'spo2.min' => 'La SpO2 doit être comprise entre 0 et 100 %.',
            'spo2.max' => 'La SpO2 doit être comprise entre 0 et 100 %.',
            'height_cm.min' => 'La taille doit être comprise entre 20 et 250 cm.',
            'height_cm.max' => 'La taille doit être comprise entre 20 et 250 cm.',
            'weight_kg.min' => 'Le poids doit être compris entre 0,1 et 500 kg.',
            'weight_kg.max' => 'Le poids doit être compris entre 0,1 et 500 kg.',
        ];
    }
}
