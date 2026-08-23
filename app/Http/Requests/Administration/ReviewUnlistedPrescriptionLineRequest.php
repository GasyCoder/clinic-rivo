<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class ReviewUnlistedPrescriptionLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.items.create');
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
        ];
    }
}
