<?php

namespace App\Http\Requests\Administration;

use App\Actions\Administration\SaveBankAction;
use App\Models\Bank;
use App\Support\Hr\BankName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-221 — une banque saisie depuis le module Banques (RH).
 */
class BankRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['name', 'phone', 'address', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $value = BankName::clean($this->input($field));
                $merge[$field] = $value === '' ? null : $value;
            }
        }

        if (is_string($this->input('code'))) {
            $merge['code'] = BankName::code($this->input('code'));
        }

        foreach (['bank_code', 'swift_code'] as $field) {
            if (is_string($this->input($field))) {
                $value = strtoupper(preg_replace('/\s+/', '', $this->input($field)));
                $merge[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($merge);
    }

    public function authorize(): bool
    {
        $bank = $this->route('bank');

        return $bank instanceof Bank
            ? ($this->user()?->can('update', $bank) ?? false)
            : ($this->user()?->can('create', Bank::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:2', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'bank_code' => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Z]+$/'],
            'swift_code' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** Le doublon est nommé avant l'écriture : « existe déjà : BOA — Bank of Africa Madagascar ». */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $bank = $this->route('bank');
            $duplicate = BankName::duplicateOf((string) $this->input('code'), (string) $this->input('name'), $bank instanceof Bank ? $bank : null);

            if ($duplicate) {
                $validator->errors()->add('name', SaveBankAction::duplicateMessage($duplicate));
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'code' => 'sigle', 'name' => 'nom', 'bank_code' => 'code banque', 'swift_code' => 'code SWIFT',
            'phone' => 'téléphone', 'address' => 'adresse', 'notes' => 'note', 'position' => 'ordre',
        ];
    }

    public function messages(): array
    {
        return [
            'bank_code.regex' => 'Le code banque ne contient que des chiffres et des lettres (5 chiffres à Madagascar).',
            'swift_code.regex' => 'Un code SWIFT compte 8 ou 11 caractères : 4 lettres de banque, 2 lettres de pays, puis la localisation.',
        ];
    }
}
