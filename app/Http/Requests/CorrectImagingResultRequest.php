<?php

namespace App\Http\Requests;

use App\Models\EpisodeOrientation;
use App\Models\ImagingRequestItem;

/**
 * La correction d'un compte rendu d'imagerie déjà enregistré (ADR-130).
 *
 * Mêmes règles de texte que la saisie ; l'autorité est distincte
 * (`imaging_results.update`) : écrire un compte rendu et revenir sur un
 * document signé ne sont pas le même droit.
 */
class CorrectImagingResultRequest extends RecordImagingResultRequest
{
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');
        $item = $this->route('imagingRequestItem');

        if (! $orientation instanceof EpisodeOrientation || ! $item instanceof ImagingRequestItem) {
            return false;
        }

        $belongs = $item->imagingRequest()
            ->whereHas('consultation', fn ($query) => $query->where('episode_orientation_id', $orientation->getKey()))
            ->exists();

        return $belongs && (bool) $this->user()?->can('imaging_results.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['reason' => ['nullable', 'string', 'max:500']];
    }
}
