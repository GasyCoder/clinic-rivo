<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "La conduite à tenir est-elle déjà déterminée ?" — answered wherever the
 * doctor happens to be (ADR-084).
 *
 * An empty `type` is the explicit answer "poursuivre l'évaluation", not a
 * missing field: the doctor is saying they are not committing yet.
 *
 * The permission of the chosen destination is re-checked here and again in
 * the action. Vue only hides what the account cannot do; it never decides.
 */
class SelectConsultationOrientationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('type') === '') {
            $this->merge(['type' => null]);
        }
    }

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
        $allowed = collect(ConsultationOrientationType::cases())
            ->filter(fn (ConsultationOrientationType $type): bool => (bool) $this->user()?->can($type->permission()))
            ->map(fn (ConsultationOrientationType $type): string => $type->value)
            ->values()
            ->all();

        return [
            'type' => ['nullable', 'string', Rule::in($allowed)],
            'priority' => ['nullable', 'string', Rule::in(array_column(ClinicalPriority::cases(), 'value'))],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Votre compte n’est pas autorisé à orienter vers cette destination.',
        ];
    }
}
