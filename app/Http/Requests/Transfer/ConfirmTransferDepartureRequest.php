<?php

namespace App\Http\Requests\Transfer;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-114 — « Transfert effectué ». */
class ConfirmTransferDepartureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('transfers.manage');
    }

    public function rules(): array
    {
        return [
            'facility' => ['nullable', 'string', 'max:255'],
            'departed_at' => ['required', 'date', 'before_or_equal:now'],
            'departure_notes' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'departed_at.required' => 'Indiquez la date et l’heure du départ.',
            'departed_at.before_or_equal' => 'Le départ ne peut pas être daté dans le futur.',
        ];
    }
}
