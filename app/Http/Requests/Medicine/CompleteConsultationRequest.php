<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-203 — clôturer la consultation, avec la
 * conduite à tenir qui part au même moment.
 *
 * `decision` est facultatif : une conduite déjà transmise, ou déjà choisie,
 * suffit. Tout le reste est facultatif lui aussi — seule l'intervention d'une
 * chirurgie est exigée, par l'action, au moment où la demande part. Les
 * droits de la destination sont revérifiés par l'action : la requête ne fait
 * que refuser tôt une forme impossible.
 */
class CompleteConsultationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $decision = $this->input('decision');

        if (! is_array($decision)) {
            return;
        }

        foreach ($decision as $key => $value) {
            if ($value === '') {
                $decision[$key] = null;
            }
        }

        $this->merge(['decision' => $decision]);
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
        return [
            'decision' => ['nullable', 'array'],
            'decision.type' => ['nullable', 'string', Rule::in(ConsultationOrientationType::values())],
            'decision.priority' => ['nullable', 'string', Rule::in(array_column(ClinicalPriority::cases(), 'value'))],
            'decision.notes' => ['nullable', 'string', 'max:3000'],
            // Sortie médicale — le transfert n'est pas un type de sortie ici.
            'decision.discharge_type' => ['nullable', 'string', Rule::in(array_map(
                fn (MedicalDischargeType $type): string => $type->value,
                MedicalDischargeType::forConsultation(),
            ))],
            'decision.discharged_at' => ['nullable', 'date', 'before_or_equal:now'],
            'decision.patient_condition' => ['nullable', 'string', 'max:3000'],
            'decision.discharge_prescription' => ['nullable', 'string', 'max:5000'],
            'decision.recommendations' => ['nullable', 'string', 'max:5000'],
            'decision.follow_up_at' => ['nullable', 'date'],
            'decision.observations' => ['nullable', 'string', 'max:5000'],
            // Chirurgie — l'intervention, choisie dans le référentiel du bloc.
            'decision.catalog_item_uuid' => [
                'nullable', 'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Surgery->value)
                    ->whereNull('deleted_at')),
            ],
            // Référence / Transfert — l'établissement, facultatif (ADR-114).
            'decision.facility' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.discharge_type.in' => 'Un transfert se décide par la conduite « Référence / Transfert », pas comme type de sortie.',
            'decision.discharged_at.before_or_equal' => 'La sortie ne peut pas être datée dans le futur.',
            'decision.catalog_item_uuid.exists' => 'Cette intervention n’existe pas dans le référentiel du bloc.',
        ];
    }

    /** @return array<string, mixed> */
    public function decision(): array
    {
        return (array) ($this->validated('decision') ?? []);
    }
}
