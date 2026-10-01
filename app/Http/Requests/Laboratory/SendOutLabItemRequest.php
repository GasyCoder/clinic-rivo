<?php

namespace App\Http\Requests\Laboratory;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-214 — confier une analyse à un laboratoire extérieur. */
class SendOutLabItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('laboratory_orders.send_out');
    }

    public function rules(): array
    {
        return [
            'laboratory' => ['required', 'string', 'min:2', 'max:150'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'laboratory.required' => 'Indiquez le laboratoire qui réalise l’analyse.',
            'laboratory.min' => 'Indiquez le laboratoire qui réalise l’analyse.',
        ];
    }
}
