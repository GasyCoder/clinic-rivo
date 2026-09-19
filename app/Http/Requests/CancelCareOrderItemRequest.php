<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelCareOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('care_orders.create');
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
