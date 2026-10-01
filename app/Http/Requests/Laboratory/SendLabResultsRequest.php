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
            // Amendement ADR-216 du 2026-09-29 (ter) — un, plusieurs ou tous les médecins proposés ;
            // `recipient_uuid` (un seul) reste accepté.
            'recipient_uuid' => ['nullable', 'uuid', 'prohibited_if:to_nobody,true'],
            'recipient_uuids' => ['nullable', 'array', 'max:30', 'prohibited_if:to_nobody,true'],
            'recipient_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /** Les destinataires choisis, sans doublon : `recipient_uuid` et `recipient_uuids` réunis. */
    public function recipientUuids(): array
    {
        return array_values(array_unique(array_filter([
            $this->validated('recipient_uuid'),
            ...($this->validated('recipient_uuids') ?? []),
        ])));
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (! $this->boolean('to_nobody') && blank($this->input('recipient_uuid')) && blank($this->input('recipient_uuids'))) {
                $validator->errors()->add('recipient_uuid', 'Choisissez au moins un médecin destinataire, ou « Aucun médecin » pour un patient externe.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Choisissez au moins une analyse à envoyer.',
            'items.min' => 'Choisissez au moins une analyse à envoyer.',
            'recipient_uuid.prohibited_if' => 'Un envoi sans médecin ne nomme aucun destinataire.',
            'recipient_uuids.prohibited_if' => 'Un envoi sans médecin ne nomme aucun destinataire.',
        ];
    }
}
