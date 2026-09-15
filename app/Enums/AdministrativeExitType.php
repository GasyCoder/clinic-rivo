<?php

namespace App\Enums;

/**
 * CDC §33.3 — how a passage leaves the clinic administratively, once the
 * doctor has already pronounced (or not) the medical discharge. The three
 * cases are exhaustive and mutually exclusive, and which one is legal is
 * decided by the account balance alone, never by the operator:
 *
 *   reste à payer = 0  -> PAID_CASH only
 *   reste à payer > 0  -> DEBT_VALIDATED or ESCAPED only
 *
 * §34.1 rule 6 ("sortie administrative définitive = solde nul, sauf
 * dérogation autorisée et tracée") is exactly that: DEBT_VALIDATED is the
 * authorised derogation, ESCAPED the recorded fact that the patient left
 * without one. §34.2 rules 8-9 forbid treating either as a settled
 * invoice, and forbid erasing the receivable they create.
 */
enum AdministrativeExitType: string
{
    case PaidCash = 'PAID_CASH';
    case DebtValidated = 'DEBT_VALIDATED';
    case Escaped = 'ESCAPED';

    public function label(): string
    {
        return match ($this) {
            self::PaidCash => 'Sorti — payé comptant',
            self::DebtValidated => 'Sorti — dette validée',
            self::Escaped => 'Sorti — évadé',
        };
    }

    /** The administrative_status this exit leaves on the episode (CDC §32). */
    public function administrativeStatus(): EpisodeAdministrativeStatus
    {
        return match ($this) {
            self::PaidCash => EpisodeAdministrativeStatus::DischargedPaid,
            self::DebtValidated => EpisodeAdministrativeStatus::DischargedDebt,
            self::Escaped => EpisodeAdministrativeStatus::DischargedEscaped,
        };
    }

    /**
     * Both non-settled exits create a receivable (§33.3). They differ in
     * who is accountable for it, not in whether the money is still owed.
     */
    public function createsDebt(): bool
    {
        return $this !== self::PaidCash;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
