<?php

namespace App\Enums;

/**
 * ADR-228 — comment une dette se rembourse, décidé par le DG : retenue sur la paie
 * du mois (ADR-227), ou espèces remises à la Caisse — seule la Caisse encaisse
 * (ADR-012). Une dette retenue sur salaire peut aussi être soldée en espèces.
 */
enum StaffDebtRepaymentMode: string
{
    case Salary = 'SALARY';
    case Cash = 'CASH';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'Retenue sur salaire',
            self::Cash => 'Espèces à la Caisse',
        };
    }
}
