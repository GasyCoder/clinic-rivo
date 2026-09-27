<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-116 — le gardien constate une sortie. Aucun motif n'est exigé : c'est
 * un constat, pas une décision (même règle que le retrait d'un acte de
 * soins, ADR-112). Les règles d'éligibilité elles-mêmes vivent dans
 * `RecordExitControlAction`, revérifiées quel que soit l'appelant.
 */
class RecordExitControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('guarding.entries.close');
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
