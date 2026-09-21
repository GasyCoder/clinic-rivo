<?php

namespace App\Enums;

/**
 * ADR-164 — l'état d'un lit. « Occupé » se lit sur les séjours et « Libre » en
 * découle ; seul « Hors service » se déclare à la main (lit cassé, fermé).
 */
enum HospitalBedState: string
{
    case Free = 'FREE';
    case Occupied = 'OCCUPIED';
    case OutOfService = 'OUT_OF_SERVICE';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Libre',
            self::Occupied => 'Occupé',
            self::OutOfService => 'Hors service',
        };
    }
}
