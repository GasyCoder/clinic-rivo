<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class ServeCareConsumablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('care_consumables.serve') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.uuid' => ['required', 'uuid', 'distinct', 'exists:care_consumable_request_lines,uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
