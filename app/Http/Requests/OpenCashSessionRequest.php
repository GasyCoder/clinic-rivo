<?php

namespace App\Http\Requests;

use App\Models\CashRegister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash.open') ?? false;
    }

    public function rules(): array
    {
        return [
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Required only once the site has actually configured a register
            // — sites that never set one up keep opening a single till exactly
            // as before, with no forced selection.
            'cash_register_uuid' => [
                Rule::requiredIf(fn () => CashRegister::query()->where('active', true)->exists()),
                'nullable', 'uuid',
                Rule::exists('cash_registers', 'uuid')->where('active', true),
            ],
        ];
    }
}
