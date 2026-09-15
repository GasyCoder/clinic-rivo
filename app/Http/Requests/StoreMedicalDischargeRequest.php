<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
use App\Models\EpisodeOrientation;
use App\Support\ConsultationWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicalDischargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && ! $orientation->episode->medicalDischarge()->exists()
            && (bool) $this->user()?->can('medical_discharge.create');
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'discharged_at', 'follow_up_at', 'transfer_destination',
            'death_occurred_at', 'death_place', 'death_causes',
            'discharge_prescription', 'recommendations', 'observations',
            'final_diagnosis',
        ] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(MedicalDischargeType::class)],
            'final_diagnosis' => [
                Rule::requiredIf(fn (): bool => $this->requiresFinalDiagnosis()),
                'nullable', 'string', 'max:5000',
            ],
            'patient_condition' => ['required', 'string', 'max:3000'],
            'discharge_prescription' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'follow_up_at' => ['nullable', 'date', 'after_or_equal:discharged_at'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'transfer_destination' => [
                Rule::requiredIf($this->input('type') === MedicalDischargeType::Transfer->value),
                'nullable', 'string', 'max:255',
            ],
            'death_occurred_at' => [
                Rule::requiredIf($this->input('type') === MedicalDischargeType::Deceased->value),
                'nullable', 'date', 'before_or_equal:discharged_at',
            ],
            'death_place' => [
                Rule::requiredIf($this->input('type') === MedicalDischargeType::Deceased->value),
                'nullable', 'string', 'max:255',
            ],
            'death_causes' => [
                Rule::requiredIf($this->input('type') === MedicalDischargeType::Deceased->value),
                'nullable', 'string', 'max:5000',
            ],
            'discharged_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'final_diagnosis.required' => 'Le diagnostic final est obligatoire pour prononcer la sortie.',
            'patient_condition.required' => 'Indiquez l’état du patient au moment de la sortie.',
            'transfer_destination.required' => 'Indiquez l’établissement ou le service de destination.',
            'death_occurred_at.required' => 'Indiquez la date et l’heure du décès.',
            'death_place.required' => 'Indiquez le lieu du décès.',
            'death_causes.required' => 'Indiquez les causes constatées du décès.',
            'discharged_at.required' => 'Indiquez la date et l’heure de la décision médicale.',
            'discharged_at.before_or_equal' => 'La sortie médicale ne peut pas être datée dans le futur.',
            'follow_up_at.after_or_equal' => 'Le rendez-vous doit être postérieur à la sortie.',
            'death_occurred_at.before_or_equal' => 'L’heure du décès ne peut pas être postérieure à la décision de sortie.',
        ];
    }

    /**
     * Le diagnostic final reste exigé pour toute vraie consultation
     * (CDC §33.1). Il ne l'est pas pour un passage venu uniquement pour un
     * ECG, une échographie ou une analyse : la conclusion de l'examen tient
     * lieu de diagnostic, et le résultat n'est souvent pas encore revenu
     * quand le médecin clôture (ADR-094).
     *
     * La règle n'est pas recopiée ici : `ConsultationWorkflow` la porte une
     * seule fois, et la garde de clôture consulte exactement la même —
     * sans quoi la sortie pourrait partir sur un dossier que la clôture
     * refuserait ensuite.
     */
    private function requiresFinalDiagnosis(): bool
    {
        $consultation = $this->route('episodeOrientation')?->consultation()->with('episode.serviceRequests')->first();

        return $consultation === null
            || app(ConsultationWorkflow::class)->requiresFinalDiagnosis($consultation);
    }
}
