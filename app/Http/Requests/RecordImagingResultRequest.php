<?php

namespace App\Http\Requests;

use App\Models\EpisodeOrientation;
use App\Models\ImagingRequestItem;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le compte rendu d'un examen d'imagerie, écrit par le médecin.
 *
 * Volontairement **non** soumis à `Consultation::isEditable()` : un ECG ou
 * une échographie peut revenir après la clôture — l'écran d'attente le dit
 * explicitement — et refuser la saisie à ce moment-là rendrait le résultat
 * impossible à consigner pour toujours. Un compte rendu n'est pas une
 * réécriture de la consultation : c'est l'acte du service qui l'a réalisé.
 */
class RecordImagingResultRequest extends FormRequest
{
    /** Valeur de `sheet_key` pour une saisie libre, sans feuille. */
    public const FREE_SHEET = 'FREE';

    public function authorize(): bool
    {
        return $this->itemBelongsToOrientation() && (bool) $this->user()?->can('imaging_results.create');
    }

    /**
     * L'examen doit appartenir à cette orientation. Sans ce contrôle, un
     * UUID valide d'un autre passage était atteignable depuis n'importe
     * quelle consultation — le même trou que
     * `CancelParaclinicalRequestRequest::target()` ferme déjà pour le
     * retrait d'une demande.
     */
    protected function itemBelongsToOrientation(): bool
    {
        $orientation = $this->route('episodeOrientation');
        $item = $this->route('imagingRequestItem');

        if (! $orientation instanceof EpisodeOrientation || ! $item instanceof ImagingRequestItem) {
            return false;
        }

        // ADR-162 — une demande du séjour n'a pas de consultation : elle
        // appartient à l'orientation du séjour qui l'a émise.
        return $item->imagingRequest()
            ->where(fn ($query) => $query
                ->whereHas('consultation', fn ($consultation) => $consultation->where('episode_orientation_id', $orientation->getKey()))
                ->orWhere(fn ($stay) => $stay
                    ->whereNotNull('hospital_stay_id')
                    ->where('source_orientation_id', $orientation->getKey())))
            ->exists();
    }

    protected function prepareForValidation(): void
    {
        // Le compte rendu est du texte mis en forme : on borne la longueur
        // sur le HTML réellement conservé, jamais sur ce que le navigateur a
        // envoyé.
        $sanitizer = app(ClinicalRichTextSanitizer::class);

        $this->merge([
            'result_value' => $sanitizer->sanitize((string) $this->input('result_value')),
            'result_notes' => $sanitizer->sanitize((string) $this->input('result_notes')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result_value' => ['required', 'string', 'max:10000'],
            'result_notes' => ['nullable', 'string', 'max:10000'],
            // La feuille choisie : `FREE` pour une saisie libre. Absente, le titre déjà
            // enregistré ne change pas.
            'sheet_key' => ['nullable', 'string', 'max:90'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'result_value.required' => 'Saisissez le compte rendu.',
            'result_value.max' => 'Le compte rendu est trop long : 10 000 caractères de mise en forme comprise.',
        ];
    }

    /**
     * Le titre de la feuille à figer sur le compte rendu (ADR-108).
     *
     * `null` : la feuille n'a pas été touchée, le titre enregistré reste.
     * `['title' => null]` : saisie libre. `['title' => '…']` : une feuille
     * connue, dont le titre est lu **côté serveur** — le navigateur ne dicte
     * jamais l'intitulé d'un document. Une feuille disparue entre-temps est
     * traitée comme non touchée.
     *
     * @return array{title: string|null}|null
     */
    public function sheetChoice(): ?array
    {
        $key = $this->input('sheet_key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        if ($key === self::FREE_SHEET) {
            return ['title' => null];
        }

        $title = app(ImagingReportTemplateCatalog::class)->titleFor($key);

        return $title === null ? null : ['title' => $title];
    }
}
