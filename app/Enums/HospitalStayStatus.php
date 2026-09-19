<?php

namespace App\Enums;

/**
 * ADR-113 — le séjour hospitalier.
 *
 * ACTIVE dès la demande du médecin (admission automatique), DISCHARGED par
 * la sortie médicale, CANCELLED quand le médecin retire sa demande avant que
 * la fiche de régime n'ait été commencée.
 */
enum HospitalStayStatus: string
{
    case Active = 'ACTIVE';
    case Discharged = 'DISCHARGED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Hospitalisé',
            self::Discharged => 'Sorti',
            self::Cancelled => 'Annulé',
        };
    }
}
