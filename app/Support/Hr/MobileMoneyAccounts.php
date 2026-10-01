<?php

namespace App\Support\Hr;

use App\Enums\MobileMoneyOperator;

/**
 * Les comptes Mobile Money d'un employé : opérateur, numéro, nom du titulaire.
 * Une personne peut en avoir plusieurs (Orange et Yas, par exemple).
 */
final class MobileMoneyAccounts
{
    public const MAX = 5;

    /**
     * Ne garde que les lignes renseignées, numéro sans espaces doublés.
     *
     * @return array<int, array{operator: ?string, number: ?string, holder: ?string}>
     */
    public static function normalize(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $operator = strtoupper(trim((string) ($row['operator'] ?? '')));
            $number = str((string) ($row['number'] ?? ''))->squish()->toString();
            $holder = str((string) ($row['holder'] ?? ''))->squish()->toString();

            if ($operator === '' && $number === '' && $holder === '') {
                continue;
            }

            $clean[] = [
                'operator' => $operator === '' ? null : $operator,
                'number' => $number === '' ? null : $number,
                'holder' => $holder === '' ? null : $holder,
            ];
        }

        return $clean;
    }

    /** @return array<int, array{operator: ?string, operator_label: ?string, number: ?string, holder: ?string}> */
    public static function present(mixed $rows): array
    {
        return array_map(fn (array $account) => [
            ...$account,
            'operator_label' => MobileMoneyOperator::tryFrom((string) $account['operator'])?->label(),
        ], self::normalize($rows));
    }
}
