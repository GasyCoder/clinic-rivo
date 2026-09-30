<?php

namespace App\Enums;

/**
 * ADR-227 — la paie d'un mois, marquée payée (le virement se fait hors RIVO),
 * annulable avec un motif : les avantages qu'elle portait repassent en attente.
 */
enum SalaryPaymentStatus: string
{
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payée',
            self::Cancelled => 'Annulée',
        };
    }
}
