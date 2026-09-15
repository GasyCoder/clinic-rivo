<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use App\Support\VitalSignRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction des constantes depuis la Consultation (ADR-093).
 *
 * Les bornes viennent de `VitalSignRules`, partagées avec la fiche Soins :
 * une tension refusée à l'infirmier ne peut pas être acceptée au médecin.
 *
 * Les champs que Médecine n'écrit jamais sont explicitement `prohibited`
 * plutôt que simplement absents des règles : un payload forgé qui les
 * porterait recevrait une erreur nommée, au lieu d'être silencieusement
 * ignoré. L'Action refuse de son côté — l'interface n'est jamais la seule
 * protection.
 */
class CorrectCareRecordVitalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->status === EpisodeOrientationStatus::InProgress
            // Après la clôture, la consultation est en lecture seule
            // (ADR-076) : la correction suit exactement la même limite que
            // le reste de l'écriture Médecine.
            && $orientation->consultation()->exists()
            && $orientation->consultation->isEditable()
            && (bool) $this->user()?->can('vitals.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...VitalSignRules::rules($this->boolean('known_diabetes') === true),
            // Le dossier permanent, les actes, le matériel et la parole des
            // Soins ne s'écrivent pas depuis une consultation.
            'allergy_note' => ['prohibited'],
            'allergy_uuids' => ['prohibited'],
            'allergen_reference_uuids' => ['prohibited'],
            'new_allergies' => ['prohibited'],
            'procedures' => ['prohibited'],
            'consumables' => ['prohibited'],
            'consumable_notes' => ['prohibited'],
            'diagnostic_note' => ['prohibited'],
            'transmission_reason' => ['prohibited'],
            'no_procedure_reason' => ['prohibited'],
            'hospitalization_reason' => ['prohibited'],
            'hospitalized_at' => ['prohibited'],
            'discharged_at' => ['prohibited'],
            'bmi' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...VitalSignRules::messages(),
            'procedures.prohibited' => 'Les actes de soins ne se saisissent pas depuis la consultation.',
            'consumables.prohibited' => 'Le matériel utilisé ne se déclare pas depuis la consultation.',
            'bmi.prohibited' => 'L’IMC est calculé par le serveur.',
        ];
    }
}
