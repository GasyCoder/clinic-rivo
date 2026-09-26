<?php

namespace App\Enums;

/**
 * ADR-197 — comment le dossier est rémunéré : un salaire, une indemnité (par
 * exemple un stagiaire indemnisé) ou rien. C'est une déclaration du RH : aucune
 * paie, retenue ni net n'en est calculé (ADR-066).
 */
enum EmployeeRemunerationType: string
{
    case Salary = 'SALARY';
    case Allowance = 'ALLOWANCE';
    case Unpaid = 'UNPAID';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'Salaire',
            self::Allowance => 'Indemnité',
            self::Unpaid => 'Non rémunéré',
        };
    }

    /** Un salaire et une indemnité ont un montant ; « non rémunéré » n'en a jamais. */
    public function hasAmount(): bool
    {
        return $this !== self::Unpaid;
    }
}
