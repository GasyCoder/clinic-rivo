<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordLabResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('laboratory_results.create');
    }

    public function rules(): array
    {
        return [
            'result_value' => ['required', 'string', 'max:2000'],
            'result_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'result_value.required' => 'Saisissez le résultat.',
        ];
    }
}
