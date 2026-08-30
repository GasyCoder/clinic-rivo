<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
