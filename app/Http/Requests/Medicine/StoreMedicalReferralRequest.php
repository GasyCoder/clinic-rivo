<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A referral needs a destination and a reason — a letter that names neither
 * tells the receiving team nothing.
 *
 * The clinical summary, the diagnosis and the treatments already given are
 * pre-filled from the interview, the examination, the paraclinical results
 * and the active prescription. The doctor corrects them; they never retype
 * them (§17).
 */
class StoreMedicalReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('transfer.request');
    }

    public function rules(): array
    {
        return [
            'facility' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:3000'],
            'diagnosis' => ['nullable', 'string', 'max:3000'],
            'clinical_summary' => ['nullable', 'string', 'max:5000'],
            'treatments_given' => ['nullable', 'string', 'max:3000'],
            'priority' => ['required', 'string', Rule::in(array_column(ClinicalPriority::cases(), 'value'))],
            'recommendations' => ['nullable', 'string', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'return_step' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'facility.required' => 'Indiquez l’établissement ou le service destinataire.',
            'reason.required' => 'Indiquez le motif de la référence.',
        ];
    }
}
