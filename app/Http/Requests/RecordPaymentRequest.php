<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'invoice_uuid' => ['required', 'uuid'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Optional: which register's session should receive this payment.
            // RecordPaymentAction auto-resolves when at most one is open
            // anywhere on the site, and requires this once two or more are.
            'cash_register_uuid' => ['nullable', 'uuid', Rule::exists('cash_registers', 'uuid')],
        ];
    }
}
