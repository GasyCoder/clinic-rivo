<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class AllocateStaffBlockCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('staff_block_credits.allocate') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
