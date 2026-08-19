<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSurgicalPreparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operating_room' => ['nullable', 'string', 'max:255'],
            'preparation_notes' => ['nullable', 'string'],
        ];
    }
}
