<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
