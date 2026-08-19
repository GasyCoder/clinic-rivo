<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSurgicalInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ended_at' => ['nullable', 'date'],
            'procedure_summary' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
