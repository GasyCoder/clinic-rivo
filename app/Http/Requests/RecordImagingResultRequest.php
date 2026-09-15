<?php

namespace App\Http\Requests;

use App\Models\EpisodeOrientation;
use App\Models\ImagingRequestItem;
use App\Services\Medicine\ClinicalRichTextSanitizer;
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
    public function authorize(): bool
    {
        $orientation = $this->route('episodeOrientation');
        $item = $this->route('imagingRequestItem');

        if (! $orientation instanceof EpisodeOrientation || ! $item instanceof ImagingRequestItem) {
            return false;
        }

        // L'examen doit appartenir à cette orientation. Sans ce contrôle, un
        // UUID valide d'un autre passage était atteignable depuis n'importe
        // quelle consultation — le même trou que
        // `CancelParaclinicalRequestRequest::target()` ferme déjà pour le
        // retrait d'une demande.
        $belongs = $item->imagingRequest()
            ->whereHas('consultation', fn ($query) => $query->where('episode_orientation_id', $orientation->getKey()))
            ->exists();

        return $belongs && (bool) $this->user()?->can('imaging_results.create');
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
            'result_value' => ['required', 'string', 'max:5000'],
            'result_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'result_value.required' => 'Saisissez le compte rendu.',
            'result_value.max' => 'Le compte rendu est trop long : 5000 caractères de mise en forme comprise.',
        ];
    }
}
