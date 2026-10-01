<?php

namespace App\Enums;

/**
 * ADR-228 — d'où vient un remboursement : la paie du mois, ou la Caisse. ADR-230 — ou le
 * solde de tout compte d'une personne qui part : retenu hors RIVO, comme la paie, et
 * constaté par le DG en réglant son départ.
 */
enum StaffDebtRepaymentSource: string
{
    case Salary = 'SALARY';
    case Cash = 'CASH';
    case FinalPay = 'FINAL_PAY';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'Retenue sur la paie',
            self::Cash => 'Espèces à la Caisse',
            self::FinalPay => 'Retenue sur le solde de tout compte',
        };
    }
}
