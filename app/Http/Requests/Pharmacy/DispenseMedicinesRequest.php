<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class DispenseMedicinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dispense = $this->route('dispense');

        return $dispense && $this->user()?->can('dispense', $dispense) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
