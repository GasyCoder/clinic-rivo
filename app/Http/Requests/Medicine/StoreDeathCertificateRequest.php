<?php

namespace App\Http\Requests\Medicine;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'acte de constatation de décès, sur le modèle de la clinique (ADR-107).
 *
 * Les causes et les observations sont en texte riche : le HTML est assaini
 * avant la validation (même liste blanche que l'interrogatoire), et la
 * longueur comme l'obligation se jugent sur le texte lu — un éditeur vidé
 * renvoie « <p><br></p> », qui ne dit aucune cause.
 */
class StoreDeathCertificateRequest extends FormRequest
{
    private const RICH_FIELDS = ['death_causes', 'observations'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('death_records.create');
    }

    protected function prepareForValidation(): void
    {
        $sanitizer = app(ClinicalRichTextSanitizer::class);

        foreach (self::RICH_FIELDS as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $sanitizer->sanitize($this->input($field))]);
            }
        }

        foreach (['identity_document_issued_on'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    public function rules(): array
    {
        $sanitizer = app(ClinicalRichTextSanitizer::class);
        $richText = fn (bool $required, int $limit): Closure => function (string $attribute, mixed $value, Closure $fail) use ($sanitizer, $required, $limit): void {
            $plain = $sanitizer->plainText(is_string($value) ? $value : '');

            if ($required && $plain === '') {
                $fail('Indiquez les causes constatées.');
            } elseif (mb_strlen($plain) > $limit) {
                $fail('Ce texte ne doit pas dépasser '.number_format($limit, 0, ',', ' ').' caractères.');
            }
        };

        return [
            // Antérieure à maintenant : un décès constaté dans le futur
            // n'est pas une constatation.
            'death_occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'death_place' => ['required', 'string', 'max:255'],
            'death_causes' => ['required', 'string', 'max:20000', $richText(true, 5000)],
            'observations' => ['nullable', 'string', 'max:20000', $richText(false, 5000)],

            // Le défunt, tel que la feuille de la clinique le décrit. Repris
            // du dossier quand il le sait, corrigeable ici ; jamais écrit en
            // retour dans le dossier patient.
            'birth_place' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'identity_document_number' => ['nullable', 'string', 'max:100'],
            'identity_document_issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'identity_document_issued_place' => ['nullable', 'string', 'max:255'],

            // « Signatures — Lieu ». La date, elle, appartient au serveur.
            'signed_place' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'death_occurred_at.required' => 'Indiquez la date et l’heure du décès.',
            'death_occurred_at.before_or_equal' => 'La date du décès ne peut pas être dans le futur.',
            'death_place.required' => 'Indiquez le lieu du décès.',
            'death_causes.required' => 'Indiquez les causes constatées.',
            'identity_document_issued_on.before_or_equal' => 'La date de délivrance ne peut pas être dans le futur.',
        ];
    }
}
