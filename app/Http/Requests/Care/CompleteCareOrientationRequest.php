<?php

namespace App\Http\Requests\Care;

use App\Enums\CareCompletionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Terminer les Soins sans nouvelle saisie (ADR-166) : la suite choisie et,
 * quand un patient attendu en Médecine est terminé aux Soins, son motif.
 * L'action revérifie tout ; cette requête ne fait que borner l'entrée.
 */
class CompleteCareOrientationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'care_outcome' => ['nullable', Rule::enum(CareCompletionMode::class)->only([CareCompletionMode::Medicine, CareCompletionMode::Finish])],
            'care_outcome_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function destination(): ?CareCompletionMode
    {
        $chosen = CareCompletionMode::tryFrom((string) $this->input('care_outcome'));

        return in_array($chosen, [CareCompletionMode::Medicine, CareCompletionMode::Finish], true) ? $chosen : null;
    }
}
