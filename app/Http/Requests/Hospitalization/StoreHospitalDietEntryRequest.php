<?php

namespace App\Http\Requests\Hospitalization;

use App\Models\HospitalDietEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** ADR-113 — une ligne de la fiche de régime : jour, heure, texte libre. */
class StoreHospitalDietEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hospital_diet.record');
    }

    public function rules(): array
    {
        $rules = [
            'served_on' => ['required', 'date', 'before_or_equal:today'],
            'served_time' => ['required', 'date_format:H:i'],
            'observation' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (HospitalDietEntry::MEAL_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        // Une ligne vide ne dit rien : au moins une colonne est renseignée.
        $validator->after(function (Validator $validator): void {
            $filled = collect([...HospitalDietEntry::MEAL_FIELDS, 'observation'])
                ->contains(fn (string $field): bool => trim((string) $this->input($field)) !== '');

            if (! $filled) {
                $validator->errors()->add('tea_bread', 'Renseignez au moins un repas ou une observation.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'served_on.required' => 'Indiquez le jour.',
            'served_on.before_or_equal' => 'Le jour ne peut pas être dans le futur.',
            'served_time.required' => 'Indiquez l’heure.',
            'served_time.date_format' => 'L’heure doit être au format HH:MM.',
        ];
    }
}
