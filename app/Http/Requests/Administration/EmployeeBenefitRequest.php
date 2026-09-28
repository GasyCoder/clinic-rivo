<?php

namespace App\Http\Requests\Administration;

use App\Enums\EmployeeBenefitFrequency;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * ADR-213 — un avantage ou une prime : type, montant, motif, fréquence, dates.
 * La création exige tout ; une correction peut n'envoyer que ce qui change
 * (enregistrement automatique de la fiche).
 */
class EmployeeBenefitRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('amount'))) {
            $amount = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $this->input('amount'));
            $merge['amount'] = $amount === '' ? null : str_replace(',', '.', $amount);
        }

        if (is_string($this->input('reason'))) {
            $merge['reason'] = str($this->input('reason'))->squish()->toString();
        }

        foreach (['ends_on'] as $field) {
            if ($this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }

    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && ($this->user()?->can('update', $employee) ?? false)
            && ($this->user()?->can('employees.payroll.update') ?? false);
    }

    public function rules(): array
    {
        $creating = $this->route('benefit') === null;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'benefit_type_uuid' => [$required, 'uuid'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => [$required, 'string', 'min:3', 'max:1000'],
            'frequency' => [$required, new Enum(EmployeeBenefitFrequency::class)],
            'starts_on' => [$required, 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    public function attributes(): array
    {
        return [
            'benefit_type_uuid' => 'type', 'amount' => 'montant', 'reason' => 'motif',
            'frequency' => 'fréquence', 'starts_on' => 'début', 'ends_on' => 'fin',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif de cet avantage.',
            'amount.decimal' => 'Le montant a au plus deux décimales.',
            'ends_on.after_or_equal' => 'La fin ne peut pas précéder le début.',
        ];
    }
}
