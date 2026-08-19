<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeletePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.delete') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
