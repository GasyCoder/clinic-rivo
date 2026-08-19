<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeletePatientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.delete') ?? false;
    }

    public function rules(): array
    {
        return [
            'patient_uuids' => ['required', 'array', 'min:1', 'max:100'],
            'patient_uuids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('patients', 'uuid')->whereNull('deleted_at'),
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
