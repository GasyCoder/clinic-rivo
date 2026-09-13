<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ConsultationStep;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveConsultationStepRequest extends FormRequest
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
            'step' => ['required', Rule::in(ConsultationStep::values())],
            'intent' => ['required', Rule::in(['COMPLETE', 'SKIP'])],
            // Why the step was not needed. Optional: "aucun examen
            // complémentaire" is a complete statement on its own, and
            // demanding prose would push the doctor to type filler.
            'skip_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'step.in' => 'Cette étape n’existe pas dans le parcours Médecine.',
            'intent.in' => 'Action inconnue sur cette étape.',
        ];
    }
}
