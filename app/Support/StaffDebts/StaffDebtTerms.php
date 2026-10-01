<?php

namespace App\Support\StaffDebts;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ADR-228 — les conditions d'une dette, vérifiées de la même façon quand l'employé
 * demande et quand le DG accorde ou ajuste : un montant, une mensualité qui ne dépasse
 * pas ce qui est à rembourser, et un premier mois de remboursement qui n'est pas passé.
 * Les limites du site (ADR-229) se vérifient à part, dans StaffDebtRules.
 */
final class StaffDebtTerms
{
    /** « 2026-10 » → le 1er octobre 2026 ; refusé s'il ne se lit pas. */
    public static function period(mixed $value, string $field): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw ValidationException::withMessages([$field => 'Choisissez le mois du premier remboursement.']);
        }

        return Carbon::createFromFormat('Y-m-d', "{$value}-01")->startOfDay();
    }

    /** ADR-229 — `$totalMinor` : ce qui est à rembourser, intérêt compris ; le montant seul sans intérêt. */
    public static function assert(int $amountMinor, int $installmentMinor, ?Carbon $firstPeriod, string $amountField, string $installmentField, string $periodField, ?int $totalMinor = null): void
    {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages([$amountField => 'Le montant doit être supérieur à zéro.']);
        }

        if ($installmentMinor <= 0) {
            throw ValidationException::withMessages([$installmentField => 'La mensualité doit être supérieure à zéro.']);
        }

        if ($installmentMinor > ($totalMinor ?? $amountMinor)) {
            throw ValidationException::withMessages([$installmentField => ($totalMinor ?? $amountMinor) > $amountMinor
                ? 'La mensualité ne peut pas dépasser ce qui est à rembourser, intérêt compris.'
                : 'La mensualité ne peut pas dépasser le montant.']);
        }

        if ($firstPeriod !== null && $firstPeriod->copy()->startOfMonth()->lt(now()->startOfMonth())) {
            throw ValidationException::withMessages([$periodField => 'Le premier remboursement ne peut pas être dans un mois déjà passé.']);
        }
    }
}
