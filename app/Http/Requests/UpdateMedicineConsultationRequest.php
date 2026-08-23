<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicineConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('consultations.update');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:3000'],
            'clinical_exam' => ['nullable', 'string', 'max:10000'],
            'decision' => ['nullable', Rule::enum(ConsultationDecision::class)],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif de la consultation.',
            'decision.enum' => 'La décision médicale sélectionnée est invalide.',
        ];
    }
}
