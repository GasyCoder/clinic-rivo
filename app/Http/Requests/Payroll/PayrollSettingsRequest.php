<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-233 — les paramètres de paie d'un site, validés de la même façon pour les enregistrer
 * et pour simuler un bulletin avant de les enregistrer. Un montant « 350 000 » ou « 1,5 »
 * se lit comme 350000 et 1.5.
 */
class PayrollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($value) => is_string($value) ? str_replace(',', '.', preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value)) : $value;
        $data = [];
        foreach (['cnaps_employee_rate', 'cnaps_employer_rate', 'cnaps_ceiling', 'health_employee_rate', 'health_employer_rate', 'health_ceiling', 'irsa_minimum', 'irsa_child_reduction', 'irsa_base_rounding'] as $key) {
            if ($this->has($key)) {
                $value = $clean($this->input($key));
                $data[$key] = $value === '' ? null : $value;
            }
        }
        if (is_array($this->input('irsa_brackets'))) {
            $data['irsa_brackets'] = array_values(array_map(fn ($bracket) => [
                'up_to' => ($v = $clean($bracket['up_to'] ?? null)) === '' ? null : $v,
                'rate' => $clean($bracket['rate'] ?? null),
            ], $this->input('irsa_brackets')));
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $rate = ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'];
        $amount = ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'];

        return [
            'legal_deductions_enabled' => ['required', 'boolean'],
            'cnaps_employee_rate' => $rate,
            'cnaps_employer_rate' => $rate,
            'cnaps_ceiling' => $amount,
            'health_label' => ['nullable', 'string', 'max:60'],
            'health_employee_rate' => $rate,
            'health_employer_rate' => $rate,
            'health_ceiling' => $amount,
            'irsa_brackets' => ['required', 'array', 'min:1', 'max:15'],
            'irsa_brackets.*.up_to' => $amount,
            'irsa_brackets.*.rate' => $rate,
            'irsa_minimum' => ['required', ...array_slice($amount, 1)],
            'irsa_child_reduction' => ['required', ...array_slice($amount, 1)],
            'irsa_base_rounding' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'allowance_subject' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $brackets = $this->input('irsa_brackets');
            if (! is_array($brackets) || collect($validator->errors()->keys())->contains(fn ($key) => str_starts_with($key, 'irsa_brackets'))) {
                return;
            }

            $previous = 0.0;
            $last = count($brackets) - 1;
            foreach (array_values($brackets) as $index => $bracket) {
                $upTo = $bracket['up_to'] ?? null;
                if ($upTo === null) {
                    if ($index !== $last) {
                        $validator->errors()->add("irsa_brackets.{$index}.up_to", 'Seule la dernière tranche est sans plafond.');
                    }

                    continue;
                }
                if ($index === $last) {
                    $validator->errors()->add("irsa_brackets.{$index}.up_to", 'La dernière tranche doit rester sans plafond (« et au-delà »).');
                }
                if ((float) $upTo <= $previous) {
                    $validator->errors()->add("irsa_brackets.{$index}.up_to", 'Chaque tranche doit commencer après la précédente : plafonds croissants.');
                }
                $previous = (float) $upTo;
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'cnaps_employee_rate' => 'taux CNAPS salarié', 'cnaps_employer_rate' => 'taux CNAPS employeur', 'cnaps_ceiling' => 'plafond CNAPS',
            'health_label' => 'nom de l’organisme médical', 'health_employee_rate' => 'taux salarié de l’organisme médical',
            'health_employer_rate' => 'taux employeur de l’organisme médical', 'health_ceiling' => 'plafond de l’organisme médical',
            'irsa_brackets' => 'tranches IRSA', 'irsa_brackets.*.up_to' => 'plafond de la tranche', 'irsa_brackets.*.rate' => 'taux de la tranche',
            'irsa_minimum' => 'IRSA minimum', 'irsa_child_reduction' => 'réduction par enfant', 'irsa_base_rounding' => 'arrondi de la base imposable',
        ];
    }

    /** Les paramètres validés, sous la forme de `PayrollSetting::snapshot()`. */
    public function settings(): array
    {
        $data = $this->validated();
        $money = fn ($value) => $value === null ? null : number_format((float) $value, 2, '.', '');

        return [
            'legal_deductions_enabled' => (bool) $data['legal_deductions_enabled'],
            'cnaps_employee_rate' => $money($data['cnaps_employee_rate']),
            'cnaps_employer_rate' => $money($data['cnaps_employer_rate']),
            'cnaps_ceiling' => $money($data['cnaps_ceiling'] ?? null),
            'health_label' => filled($data['health_label'] ?? null) ? trim($data['health_label']) : null,
            'health_employee_rate' => $money($data['health_employee_rate']),
            'health_employer_rate' => $money($data['health_employer_rate']),
            'health_ceiling' => $money($data['health_ceiling'] ?? null),
            'irsa_brackets' => array_values(array_map(fn ($bracket) => ['up_to' => $money($bracket['up_to'] ?? null), 'rate' => $money($bracket['rate'])], $data['irsa_brackets'])),
            'irsa_minimum' => $money($data['irsa_minimum']),
            'irsa_child_reduction' => $money($data['irsa_child_reduction']),
            'irsa_base_rounding' => isset($data['irsa_base_rounding']) ? (int) $data['irsa_base_rounding'] : null,
            'allowance_subject' => (bool) $data['allowance_subject'],
        ];
    }
}
