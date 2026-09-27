<?php

namespace App\Http\Requests\Transfer;

use App\Enums\ClinicalPriority;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-114 — compléter une demande de transfert.
 *
 * Les champs rédigés sont en texte riche : le HTML est assaini avant la
 * validation (même liste blanche que l'interrogatoire), et la longueur se
 * compte sur le texte lu, jamais sur les balises.
 */
class UpdateMedicalReferralRequest extends FormRequest
{
    /** Longueur maximale du texte lu, par champ. */
    private const LIMITS = [
        'reason' => 3000,
        'diagnosis' => 3000,
        'clinical_summary' => 5000,
        'treatments_given' => 3000,
        'recommendations' => 3000,
        'notes' => 3000,
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('transfers.manage');
    }

    protected function prepareForValidation(): void
    {
        $sanitizer = app(ClinicalRichTextSanitizer::class);

        foreach (array_keys(self::LIMITS) as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $sanitizer->sanitize($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $sanitizer = app(ClinicalRichTextSanitizer::class);
        $rules = [
            'facility' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::enum(ClinicalPriority::class)],
        ];

        foreach (self::LIMITS as $field => $limit) {
            $rules[$field] = [
                'nullable',
                'string',
                'max:20000',
                function (string $attribute, mixed $value, Closure $fail) use ($sanitizer, $limit): void {
                    if (mb_strlen($sanitizer->plainText(is_string($value) ? $value : '')) > $limit) {
                        $fail('Ce champ ne doit pas dépasser '.number_format($limit, 0, ',', ' ').' caractères.');
                    }
                },
            ];
        }

        return $rules;
    }
}
