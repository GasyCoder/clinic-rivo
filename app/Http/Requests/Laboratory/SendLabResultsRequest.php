<?php

namespace App\Http\Requests\Laboratory;

use App\Actions\Laboratory\SendLabResultsAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-216 — envoyer des résultats au médecin : quelles analyses, et à qui.
 * Un médecin, ou « aucun » (patient externe) choisi explicitement : jamais un
 * envoi sans décision sur le destinataire.
 */
class SendLabResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(SendLabResultsAction::PERMISSION);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*' => ['required', 'uuid', 'distinct'],
            'to_nobody' => ['sometimes', 'boolean'],
            'recipient_uuid' => ['nullable', 'uuid', 'required_unless:to_nobody,true', 'prohibited_if:to_nobody,true'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Choisissez au moins une analyse à envoyer.',
            'items.min' => 'Choisissez au moins une analyse à envoyer.',
            'recipient_uuid.required_unless' => 'Choisissez le médecin destinataire, ou « Aucun médecin » pour un patient externe.',
            'recipient_uuid.prohibited_if' => 'Un envoi sans médecin ne nomme aucun destinataire.',
        ];
    }
}
