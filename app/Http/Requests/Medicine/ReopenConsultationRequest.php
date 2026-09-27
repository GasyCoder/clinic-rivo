<?php

namespace App\Http\Requests\Medicine;

use App\Enums\CatalogModule;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rouvrir une consultation clôturée.
 *
 * Volontairement **sans** la garde `status === InProgress` que portent les
 * autres écritures Médecine : une consultation clôturée a justement terminé
 * son orientation, et l'exiger ici rendrait la réouverture impossible — le
 * serpent qui se mord la queue.
 *
 * Le motif est obligatoire, contrairement au saut d'une étape (ADR-076) où
 * « aucun examen complémentaire » se suffit à lui-même. Ici, revenir sur un
 * dossier médical déjà conclu est un acte exceptionnel : l'audit doit dire
 * pourquoi, sans quoi la trace ne raconte rien à qui la relira.
 */
class ReopenConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        return $orientation instanceof EpisodeOrientation
            && $orientation->destination_module === CatalogModule::Medicine
            && $orientation->consultation()->exists()
            && (bool) $this->user()?->can('consultations.reopen');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez pourquoi cette consultation est rouverte.',
            'reason.min' => 'Précisez le motif : il sera relu dans l’audit du dossier.',
        ];
    }
}
