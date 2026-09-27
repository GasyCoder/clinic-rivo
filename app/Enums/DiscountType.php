<?php

namespace App\Enums;

use App\Support\Money;

/**
 * ADR-192 — une remise est un pourcentage de la part patient, ou un montant fixe.
 * Un montant ne descend jamais sous zéro : il est plafonné à ce que le patient doit.
 */
enum DiscountType: string
{
    case Percent = 'PERCENT';
    case Amount = 'AMOUNT';

    /**
     * Une règle réglée (type + valeur), ou `null` si elle ne l'est pas entièrement.
     *
     * @return array{type: self, value: string}|null
     */
    public static function rule(self|string|null $type, int|float|string|null $value): ?array
    {
        $type = $type instanceof self ? $type : self::tryFrom((string) $type);

        if ($type === null || $value === null || (float) $value <= 0) {
            return null;
        }

        return ['type' => $type, 'value' => (string) $value];
    }

    /** La remise, en unités mineures, sur une part patient donnée (jamais plus que cette part). */
    public function amountOn(int $baseMinor, string $value): int
    {
        if ($baseMinor <= 0) {
            return 0;
        }

        return match ($this) {
            self::Percent => min($baseMinor, Money::percentage($baseMinor, Money::normalize($value))),
            self::Amount => min($baseMinor, Money::toMinor($value)),
        };
    }

    /** « 10 % » ou « 5 000 Ar » : ce que la remise vaut, écrit comme l'écran l'affiche. */
    public function describe(string $value): string
    {
        return match ($this) {
            self::Percent => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',').' %',
            self::Amount => number_format((float) $value, 0, ',', "\u{202F}").' Ar',
        };
    }
}
