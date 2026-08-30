<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePatientAntecedentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.medical_history.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
