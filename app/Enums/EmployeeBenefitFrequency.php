<?php

namespace App\Enums;

/**
 * ADR-221 — un avantage revient chaque mois (logement, transport…) ou n'est
 * versé qu'une fois (une prime). Une déclaration : rien n'en est calculé.
 */
enum EmployeeBenefitFrequency: string
{
    case Monthly = 'MONTHLY';
    case OneTime = 'ONE_TIME';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Chaque mois',
            self::OneTime => 'Une fois',
        };
    }
}
