<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkCareOrderItemNotPerformedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('care.update');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez pourquoi cet acte n’a pas été réalisé.',
        ];
    }
}
