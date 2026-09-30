<?php

namespace App\Enums;

/** ADR-228 — d'où vient un remboursement : la paie du mois, ou la Caisse. */
enum StaffDebtRepaymentSource: string
{
    case Salary = 'SALARY';
    case Cash = 'CASH';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'Retenue sur la paie',
            self::Cash => 'Espèces à la Caisse',
        };
    }
}
