<?php

namespace App\Http\Requests;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash.close') ?? false;
    }

    public function rules(): array
    {
        return [
            'actual_closing_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'notes' => [Rule::requiredIf($this->hasVariance()), 'nullable', 'string', 'max:1000'],
            // Never chosen here — the workspace already knows which register
            // it's closing. Nullable so a legacy site with no registers keeps
            // closing its single till exactly as before.
            'cash_register_uuid' => ['nullable', 'uuid', Rule::exists('cash_registers', 'uuid')],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'Le montant compté diffère du montant attendu : précisez l’écart en observation.',
        ];
    }

    private function hasVariance(): bool
    {
        $actual = $this->input('actual_closing_amount');

        if (! is_numeric($actual)) {
            return false;
        }

        $registerUuid = $this->input('cash_register_uuid');
        $register = $registerUuid ? CashRegister::query()->where('uuid', $registerUuid)->first() : null;

        $session = CashSession::query()->where('active_key', CashSession::activeKeyFor($register))->first();

        if (! $session) {
            return false;
        }

        return Money::toMinor((string) $actual) !== Money::toMinor($session->computeExpectedClosingAmount());
    }
}
