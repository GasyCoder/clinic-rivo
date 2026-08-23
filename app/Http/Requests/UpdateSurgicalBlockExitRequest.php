<?php

namespace App\Http\Requests;

use App\Enums\SurgicalAwakeningStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSurgicalBlockExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'block_entered_at' => ['nullable', 'date'],
            'block_exited_at' => ['nullable', 'date'],
            'perfusion_serum' => ['nullable', 'string', 'max:255'],
            'perfusion_bag' => ['nullable', 'string', 'max:255'],
            'transfusion_blood' => ['nullable', 'string', 'max:255'],
            'transfusion_quantity' => $this->quantityRules(),
            'transfusion_unit' => ['nullable', 'string', 'max:30'],
            'urine_appearance' => ['nullable', 'string', 'max:255'],
            'urine_quantity' => $this->quantityRules(),
            'urine_unit' => ['nullable', 'string', 'max:30'],
            'blood_loss_quantity' => $this->quantityRules(),
            'blood_loss_unit' => ['nullable', 'string', 'max:30'],
            'drug_name' => ['nullable', 'string', 'max:255'],
            'drug_quantity' => $this->quantityRules(),
            'drug_unit' => ['nullable', 'string', 'max:30'],
            'antibiotic_name' => ['nullable', 'string', 'max:255'],
            'antibiotic_quantity' => $this->quantityRules(),
            'antibiotic_unit' => ['nullable', 'string', 'max:30'],
            'awakening_status' => ['nullable', Rule::enum(SurgicalAwakeningStatus::class)],
            'awakening_score' => ['nullable', 'string', 'max:50'],
        ];

        foreach (['entry', 'exit'] as $phase) {
            $rules["{$phase}_blood_pressure_systolic"] = ['nullable', 'integer', 'min:40', 'max:300', "required_with:{$phase}_blood_pressure_diastolic", "gt:{$phase}_blood_pressure_diastolic"];
            $rules["{$phase}_blood_pressure_diastolic"] = ['nullable', 'integer', 'min:20', 'max:200', "required_with:{$phase}_blood_pressure_systolic", "lt:{$phase}_blood_pressure_systolic"];
            $rules["{$phase}_heart_rate"] = ['nullable', 'integer', 'min:20', 'max:300'];
            $rules["{$phase}_oxygen_saturation"] = ['nullable', 'integer', 'min:0', 'max:100'];
            $rules["{$phase}_respiratory_rate"] = ['nullable', 'integer', 'min:1', 'max:150'];
            $rules["{$phase}_temperature_celsius"] = ['nullable', 'numeric', 'min:25', 'max:45', 'decimal:0,2'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('block_entered_at') || ! $this->filled('block_exited_at')) {
                return;
            }

            if (strtotime((string) $this->input('block_exited_at')) < strtotime((string) $this->input('block_entered_at'))) {
                $validator->errors()->add('block_exited_at', 'L’heure de sortie doit être postérieure ou égale à l’heure d’entrée.');
            }
        }];
    }

    private function quantityRules(): array
    {
        return ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'];
    }
}
