<?php

namespace App\Http\Requests;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un brouillon est une saisie non validée : une tension à moitié écrite ou un
 * dossier incomplet est précisément ce qui doit survivre à une actualisation.
 * Le contenu est borné et ses sections listées, mais ses valeurs ne sont pas
 * validées cliniquement ici — cela se fait à l'enregistrement réel.
 */
class SaveMaternityRecordDraftRequest extends FormRequest
{
    /** Les formulaires du dossier dont la saisie vaut d'être gardée ; le reste est ignoré. */
    public const ALLOWED_SECTIONS = ['record', 'basket', 'cesarean'];

    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');

        if (! $orientation instanceof EpisodeOrientation
            || $orientation->destination_module !== CatalogModule::Maternity
            || $orientation->status !== EpisodeOrientationStatus::InProgress) {
            return false;
        }

        // Le droit d'écrire le dossier lui-même, et seulement pendant la prise en charge.
        $exists = $orientation->episode()->whereHas('maternityRecord')->exists();

        return (bool) $this->user()?->can($exists ? 'maternity.update' : 'maternity.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['payload' => ['required', 'array']];
    }

    /**
     * Liste blanche bornée en taille — jamais une donnée clinique de confiance.
     *
     * @return array<string, mixed>
     */
    public function draftPayload(): array
    {
        // input() et non validated() : l'extraction de règles imbriquées
        // supprimerait toute clé non nommée, et la forme d'un brouillon est
        // celle du formulaire, pas la nôtre.
        $payload = collect($this->input('payload', []))
            ->only(self::ALLOWED_SECTIONS)
            ->filter(fn ($section) => is_array($section))
            ->all();

        // Un brouillon qui ne tient plus n'est pas à moitié stocké : ce qui est
        // à l'écran reste, et l'enregistrement réel reste disponible.
        return mb_strlen(json_encode($payload) ?: '') > 128_000 ? [] : $payload;
    }
}
