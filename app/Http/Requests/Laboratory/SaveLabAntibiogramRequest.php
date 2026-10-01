<?php

namespace App\Http\Requests\Laboratory;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-213 — les lignes S / I / R d'un antibiogramme. */
class SaveLabAntibiogramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('laboratory_results.create');
    }

    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:60'],
            'lines.*.antibiotic_uuid' => ['required', 'uuid'],
            'lines.*.interpretation' => ['nullable', 'in:S,I,R'],
            'lines.*.measure' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'lines.*.measure_unit' => ['nullable', 'string', 'max:15'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['lines.*.measure.numeric' => 'La mesure est un nombre (diamètre en mm).'];
    }
}
