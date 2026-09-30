<?php

namespace App\Enums;

/**
 * Comment le personnel reçoit sa rémunération : virement sur son compte, Mobile Money
 * ou espèces (les colonnes de la feuille de paie de la clinique). Une déclaration du RH :
 * aucune paie n'en est calculée (ADR-066).
 */
enum SalaryPaymentMode: string
{
    case Bank = 'BANK';
    case MobileMoney = 'MOBILE_MONEY';
    case Cash = 'CASH';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Virement bancaire',
            self::MobileMoney => 'Mobile Money',
            self::Cash => 'Espèces',
        };
    }
}
