<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the motif and the priority are required.
 *
 * Everything else — diagnosis, clinical summary, planned treatment — is
 * pre-filled from what the doctor already recorded and stays correctable,
 * never re-asked (§17). Requiring them would turn a reused value into a
 * field to retype.
 */
class StoreHospitalizationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('hospitalization.request');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:3000'],
            'admission_diagnosis' => ['nullable', 'string', 'max:3000'],
            'clinical_summary' => ['nullable', 'string', 'max:5000'],
            'planned_treatment' => ['nullable', 'string', 'max:3000'],
            'requested_service' => ['nullable', 'string', 'max:150'],
            'requested_admission_at' => ['nullable', 'date'],
            'priority' => ['required', 'string', Rule::in(array_column(ClinicalPriority::cases(), 'value'))],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'return_step' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif de l’hospitalisation.',
        ];
    }
}
