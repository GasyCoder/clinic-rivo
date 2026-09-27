<?php

namespace App\Http\Requests;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Validation\Validator;

/**
 * L'aperçu d'un compte rendu d'imagerie avant son enregistrement (ADR-108).
 *
 * Mêmes règles de texte que la saisie. Il sert aussi bien à une première
 * saisie qu'à une correction, donc l'un ou l'autre droit suffit : l'aperçu
 * n'écrit rien.
 */
class PreviewImagingResultRequest extends RecordImagingResultRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $this->itemBelongsToOrientation()
            && (bool) ($user?->can('imaging_results.create') || $user?->can('imaging_results.update'));
    }

    /**
     * Un aperçu de rien n'a pas de sens : une feuille dont toutes les cases
     * sont vides (`<hr>` seuls) passe `required`, mais n'est pas un compte
     * rendu.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (app(ClinicalRichTextSanitizer::class)->isBlank((string) $this->input('result_value'))) {
                    $validator->errors()->add('result_value', 'Saisissez le compte rendu.');
                }
            },
        ];
    }
}
