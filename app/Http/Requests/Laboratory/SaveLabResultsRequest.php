<?php

namespace App\Http\Requests\Laboratory;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-213 — l'enregistrement automatique de la paillasse ; l'action relit chaque ligne selon son mode. */
class SaveLabResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('laboratory_results.create');
    }

    public function rules(): array
    {
        return [
            'results' => ['present', 'array', 'max:300'],
            'results.*.analysis_uuid' => ['required', 'uuid'],
            'results.*.value' => ['nullable', 'max:2000'],
            'results.*.selections' => ['nullable', 'array', 'max:40'],
            'results.*.interpretation' => ['sometimes', 'nullable', 'in:NORMAL,PATHOLOGICAL'],
            'conclusion' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];
    }
}
