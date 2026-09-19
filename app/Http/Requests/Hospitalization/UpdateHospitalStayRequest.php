<?php

namespace App\Http\Requests\Hospitalization;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-113 — le service et la chambre / le lit, en texte libre et facultatifs. */
class UpdateHospitalStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hospitalization.update');
    }

    public function rules(): array
    {
        return [
            'room_bed' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:150'],
        ];
    }
}
