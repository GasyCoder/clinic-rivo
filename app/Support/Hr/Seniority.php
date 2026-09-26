<?php

namespace App\Support\Hr;

use Carbon\CarbonInterface;

/**
 * ADR-197 — l'ancienneté de service, calculée depuis la date d'entrée, jamais
 * saisie : elle est toujours à jour. Le même calcul existe à l'écran
 * (`utilities/seniority.js`) pour l'aperçu pendant la saisie.
 */
final class Seniority
{
    /** @return array{years: int, months: int, label: string, future: bool}|null */
    public static function of(?CarbonInterface $hireDate, ?CarbonInterface $today = null): ?array
    {
        if (! $hireDate) {
            return null;
        }

        $today = ($today ?? today())->copy()->startOfDay();
        $hired = $hireDate->copy()->startOfDay();

        if ($hired->isAfter($today)) {
            return ['years' => 0, 'months' => 0, 'label' => 'Entrée à venir', 'future' => true];
        }

        $interval = $hired->diff($today);

        return [
            'years' => $interval->y,
            'months' => $interval->m,
            'label' => self::label($interval->y, $interval->m),
            'future' => false,
        ];
    }

    public static function label(int $years, int $months): string
    {
        $parts = array_filter([
            $years > 0 ? $years.' an'.($years > 1 ? 's' : '') : null,
            $months > 0 ? $months.' mois' : null,
        ]);

        return $parts === [] ? 'Moins d’un mois' : implode(' ', $parts);
    }
}
