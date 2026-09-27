<?php

namespace App\Enums;

/**
 * L'état d'un rendez-vous (ADR-204).
 *
 * Un rendez-vous est un événement futur, jamais un passage : il ne crée aucun
 * `Episode`. La patiente qui revient passe par la Réception, qui ouvre son
 * nouveau passage.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programmé',
            self::Completed => 'Honoré',
            self::Cancelled => 'Annulé',
            self::NoShow => 'Non honoré',
        };
    }
}
