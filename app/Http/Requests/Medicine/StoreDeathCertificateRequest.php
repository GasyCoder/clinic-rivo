<?php

namespace App\Http\Requests\Medicine;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeathCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('death_records.create');
    }

    public function rules(): array
    {
        return [
            // Antérieure à maintenant : un décès constaté dans le futur
            // n'est pas une constatation.
            'death_occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'death_place' => ['required', 'string', 'max:255'],
            'death_causes' => ['required', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'death_occurred_at.required' => 'Indiquez la date et l’heure du décès.',
            'death_occurred_at.before_or_equal' => 'La date du décès ne peut pas être dans le futur.',
            'death_place.required' => 'Indiquez le lieu du décès.',
            'death_causes.required' => 'Indiquez les causes constatées.',
        ];
    }
}
