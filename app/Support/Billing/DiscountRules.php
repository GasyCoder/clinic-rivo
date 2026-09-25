<?php

namespace App\Support\Billing;

use App\Enums\DiscountType;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

/**
 * ADR-192 — la forme d'une remise, écrite une fois pour les réglages VIP et
 * personnel, la remise d'un patient et les coupons : un pourcentage entre 0 et
 * 100 (exclu 0), ou un montant positif, à deux décimales au plus.
 */
final class DiscountRules
{
    public const MAX_AMOUNT = 999_999_999;

    /**
     * @param  string  $type  le champ du type
     * @param  string  $value  le champ de la valeur
     * @param  bool  $required  `false` pour un réglage facultatif (VIP, personnel)
     * @return array<string, array<int, mixed>>
     */
    public static function pair(string $type, string $value, bool $required = true): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            $type => [$presence, Rule::enum(DiscountType::class)],
            $value => [
                $required ? 'required' : "required_with:{$type}",
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
                Rule::when(fn (Fluent $input) => $input->get($type) === DiscountType::Percent->value, ['max:100'], ['max:'.self::MAX_AMOUNT]),
            ],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $type, string $value): array
    {
        return [
            "{$type}.required" => 'Choisissez un pourcentage ou un montant.',
            "{$type}.enum" => 'Choisissez un pourcentage ou un montant.',
            "{$value}.required" => 'Indiquez la valeur de la remise.',
            "{$value}.required_with" => 'Indiquez la valeur de la remise.',
            "{$value}.numeric" => 'La remise est un nombre.',
            "{$value}.decimal" => 'Deux décimales au plus.',
            "{$value}.gt" => 'La remise doit être supérieure à zéro.',
            "{$value}.max" => 'Un pourcentage ne dépasse pas 100 %.',
        ];
    }
}
