<?php

namespace App\Support\StaffDebts;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * ADR-229 — l'intérêt d'une dette du personnel, par tranche de montant : chaque tranche
 * (de … à …, la dernière pouvant être sans plafond) porte un intérêt fixe en ariary ou
 * un pourcentage du montant emprunté. L'intérêt s'ajoute une fois au montant et se
 * rembourse avec lui, réparti sur les mensualités. Un montant qu'aucune tranche ne couvre
 * ne porte pas d'intérêt.
 *
 * Arrondi à l'ariary près. `resources/js/utilities/staffDebts.js` (interestFor) écrit la
 * même règle pour l'aperçu pendant la saisie ; le serveur recalcule toujours.
 */
final class StaffDebtInterest
{
    public const FIXED = 'FIXED';

    public const PERCENT = 'PERCENT';

    public const MAX_TIERS = 20;

    /**
     * L'intérêt d'un montant selon les tranches.
     *
     * @param  list<array{from: string, to: ?string, mode: string, value: string}>  $tiers
     * @return array{mode: string, value: string, amount_minor: int, from: string, to: ?string}|null
     */
    public static function for(int $amountMinor, array $tiers): ?array
    {
        if ($amountMinor <= 0) {
            return null;
        }

        foreach ($tiers as $tier) {
            $from = Money::toMinor((string) $tier['from']);
            $to = $tier['to'] === null ? null : Money::toMinor((string) $tier['to']);

            if ($amountMinor < $from || ($to !== null && $amountMinor > $to)) {
                continue;
            }

            $interest = $tier['mode'] === self::PERCENT
                ? intdiv(Money::percentage($amountMinor, (string) $tier['value']) + 50, 100) * 100
                : Money::toMinor((string) $tier['value']);

            return [
                'mode' => $tier['mode'],
                'value' => Money::normalize((string) $tier['value']),
                'amount_minor' => $interest,
                'from' => Money::normalize((string) $tier['from']),
                'to' => $tier['to'] === null ? null : Money::normalize((string) $tier['to']),
            ];
        }

        return null;
    }

    /**
     * Des tranches saisies → des tranches propres, dans l'ordre, ou un refus nommé : chaque
     * tranche a un début, une fin plus grande (sauf la dernière, qui peut être sans
     * plafond), un intérêt positif — un pourcentage d'au plus 100 — et ne chevauche pas
     * la suivante.
     *
     * @param  array<int, mixed>  $input
     * @return list<array{from: string, to: ?string, mode: string, value: string}>
     */
    public static function normalize(array $input, string $field = 'interest_tiers'): array
    {
        if (count($input) > self::MAX_TIERS) {
            throw ValidationException::withMessages([$field => 'Au plus '.self::MAX_TIERS.' tranches.']);
        }

        $tiers = [];
        foreach (array_values($input) as $index => $row) {
            $key = "{$field}.{$index}";
            $row = is_array($row) ? $row : [];
            $from = self::amount($row['from'] ?? null);
            $to = filled($row['to'] ?? null) ? self::amount($row['to']) : null;
            $mode = in_array($row['mode'] ?? null, [self::FIXED, self::PERCENT], true) ? $row['mode'] : null;
            $value = self::amount($row['value'] ?? null);

            if ($from === null) {
                throw ValidationException::withMessages(["{$key}.from" => 'Tranche '.($index + 1).' : indiquez le montant de début.']);
            }

            if (filled($row['to'] ?? null) && $to === null) {
                throw ValidationException::withMessages(["{$key}.to" => 'Tranche '.($index + 1).' : le montant de fin ne se lit pas.']);
            }

            if ($to !== null && Money::toMinor($to) <= Money::toMinor($from)) {
                throw ValidationException::withMessages(["{$key}.to" => 'Tranche '.($index + 1).' : la fin doit dépasser le début.']);
            }

            if ($mode === null) {
                throw ValidationException::withMessages(["{$key}.mode" => 'Tranche '.($index + 1).' : choisissez un montant fixe ou un pourcentage.']);
            }

            if ($value === null || Money::toMinor($value) <= 0) {
                throw ValidationException::withMessages(["{$key}.value" => 'Tranche '.($index + 1).' : l’intérêt doit être supérieur à zéro.']);
            }

            if ($mode === self::PERCENT && Money::toMinor($value) > 10_000) {
                throw ValidationException::withMessages(["{$key}.value" => 'Tranche '.($index + 1).' : un pourcentage ne dépasse pas 100.']);
            }

            $tiers[] = ['from' => $from, 'to' => $to, 'mode' => $mode, 'value' => $value];
        }

        usort($tiers, fn (array $a, array $b) => Money::toMinor($a['from']) <=> Money::toMinor($b['from']));

        foreach ($tiers as $index => $tier) {
            $next = $tiers[$index + 1] ?? null;
            if ($next === null) {
                continue;
            }

            if ($tier['to'] === null) {
                throw ValidationException::withMessages([$field => 'Seule la dernière tranche peut être sans plafond.']);
            }

            if (Money::toMinor($next['from']) <= Money::toMinor($tier['to'])) {
                throw ValidationException::withMessages([$field => 'Les tranches se chevauchent : chacune doit commencer après la fin de la précédente.']);
            }
        }

        return $tiers;
    }

    /** « 1 000 000,50 » / « 1000000.5 » → « 1000000.50 » ; null s'il ne se lit pas. */
    private static function amount(mixed $value): ?string
    {
        $text = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $value));

        return preg_match('/^\d+(\.\d{1,2})?$/', $text) ? Money::normalize($text) : null;
    }
}
