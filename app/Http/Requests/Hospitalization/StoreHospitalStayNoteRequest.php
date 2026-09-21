<?php

namespace App\Http\Requests\Hospitalization;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-162 — la note quotidienne courte : au moins une des quatre rubriques. */
class StoreHospitalStayNoteRequest extends FormRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        return $this->stayAllows('hospital_notes.create');
    }

    public function rules(): array
    {

        return [
            'subjective' => ['nullable', 'string', 'max:3000', 'required_without_all:objective,assessment,plan'],
            'objective' => ['nullable', 'string', 'max:3000'],
            'assessment' => ['nullable', 'string', 'max:3000'],
            'plan' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'subjective.required_without_all' => 'Écrivez au moins une des quatre rubriques de la note.',
        ];
    }
}
