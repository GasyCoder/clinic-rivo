<?php

namespace App\Http\Requests\Bonus;

use App\Enums\BonusMeasure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * ADR-212 — une catégorie de bonus : un nom, ce qu'elle compte, un seuil
 * mensuel (au moins un patient), un montant fixe en Ariary, et les membres du
 * personnel concernés. Les droits sont revérifiés par l'action.
 */
class BonusCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['name', 'description'])
            ->filter(fn (string $field) => is_string($this->input($field)))
            ->mapWithKeys(fn (string $field) => [$field => str($this->input($field))->squish()->toString() ?: null])
            ->all());

        if (is_string($this->input('amount'))) {
            // « 20 000 » ou « 20 000,50 » : l'écriture de l'Ariary.
            $this->merge(['amount' => str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $this->input('amount'))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'measure' => ['required', new Enum(BonusMeasure::class)],
            'threshold' => ['required', 'integer', 'min:1', 'max:100000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'employee_uuids' => ['sometimes', 'array', 'max:500'],
            'employee_uuids.*' => ['uuid', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'measure' => 'ce qui est compté',
            'threshold' => 'seuil',
            'amount' => 'montant',
            'description' => 'description',
            'employee_uuids' => 'personnel concerné',
        ];
    }
}
