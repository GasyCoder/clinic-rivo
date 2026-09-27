<?php

namespace App\Http\Requests;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-116 — une ligne saisie à la main du journal de traitement.
 *
 * Le texte est en éditeur riche (ClinicalRichTextEditor), comme le reste des
 * champs cliniques ; la longueur se vérifie sur le texte lu, jamais sur les
 * balises (même règle que l'interrogatoire, ADR-078).
 */
class StoreTreatmentJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('treatment_journal.record');
    }

    public function rules(): array
    {
        return [
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'description' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $plain = app(ClinicalRichTextSanitizer::class)->plainText((string) $this->input('description'));

            if (mb_strlen($plain) > 3000) {
                $validator->errors()->add('description', 'La description dépasse 3000 caractères.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'occurred_at.required' => 'Indiquez la date et l’heure.',
            'occurred_at.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'description.required' => 'Décrivez le traitement administré.',
        ];
    }
}
