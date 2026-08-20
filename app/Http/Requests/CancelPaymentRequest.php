<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
